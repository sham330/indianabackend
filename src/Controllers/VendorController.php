<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use src\Core\Mailer;
use Firebase\JWT\JWT;
use InvalidArgumentException;
use RuntimeException;
use Exception;

class VendorController
{
    private string $jwtSecret;

    public function __construct()
    {
        $this->jwtSecret = getenv('JWT_SECRET') ?: 'your-super-secret-key-change-this';
    }

    private function generateUuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}


/**
 * Get vendor profile by authenticated user_id
 * Removes slug and page_id dependencies
 */
/**
 * Get vendor profile + HTML pages by authenticated user_id
 */
public function getVendor(): void
{
    try {
        // 1️⃣ AuthMiddleware already ran and set $_SERVER['AUTH_USER']
        if (!isset($_SERVER['AUTH_USER'])) {
            http_response_code(401);
            echo json_encode(['message' => 'Authentication required']);
            return;
        }

        $userId = $_SERVER['AUTH_USER']['id'];
        $db = Database::connect();

        // 2️⃣ Get vendor details by user_id
        $stmt = $db->prepare("
            SELECT 
                v.id, v.user_id, v.business_image, v.business_name, 
                v.description, v.business_type, v.address,v.google_map_link,v.no_of_ads, v.status as vendor_status,
                v.created_at, v.updated_at,
                u.first_name, u.last_name, u.email
            FROM vendors v
            LEFT JOIN users u ON v.user_id = u.id
            WHERE v.user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $vendor = $stmt->fetch();

        if (!$vendor) {
            http_response_code(404);
            echo json_encode([
                'success' => false, 
                'message' => 'No vendor profile found for this user'
            ]);
            return;
        }

        // 3️⃣ Get HTML pages where user_id matches id (only id, seo_title, status)
        $pagesStmt = $db->prepare("
            SELECT id, seo_title, status 
            FROM html_pages 
            WHERE user_id = :user_id
        ");
        $pagesStmt->execute(['user_id' => $userId]);
        $pages = $pagesStmt->fetchAll();

        // 4️⃣ Format response
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'vendor' => [
                'id' => $vendor['id'],
                'user_id' => $vendor['user_id'],
                'business_name' => $vendor['business_name'],
                'business_type' => $vendor['business_type'],
                'description' => $vendor['description'],
                'address' => $vendor['address'],
                 'ads' => $vendor['no_of_ads'],
                'google_map_link' => $vendor['google_map_link'],
                'business_image' => $vendor['business_image'] ? "{$vendor['business_image']}" : null,
                'status' => $vendor['vendor_status'],
                'created_at' => $vendor['created_at'],
                'updated_at' => $vendor['updated_at'],
                'user' => [
                    'first_name' => $vendor['first_name'],
                    'last_name' => $vendor['last_name'],
                    'email' => $vendor['email']
                ]
            ],
            'pages' => array_map(function($page) {
                return [
                    'id' => $page['id'],
                    'seo_title' => $page['seo_title'],
                    'status' => $page['status']
                ];
            }, $pages)
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        error_log("Get vendor error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    }
}

public function updateVendor(): void
{
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    try {
        // 1️⃣ Check authentication
        if (!isset($_SERVER['AUTH_USER'])) {
            http_response_code(401);
            echo json_encode(['message' => 'Authentication required']);
            return;
        }

        $userId = $_SERVER['AUTH_USER']['id'];
        $db = Database::connect();

        // 2️⃣ Check if vendor exists
        $check = $db->prepare("SELECT id FROM vendors WHERE user_id = :user_id");
        $check->execute(['user_id' => $userId]);
        $vendor = $check->fetch();

        if (!$vendor) {
            http_response_code(404);
            echo json_encode(['message' => 'Vendor profile not found']);
            return;
        }

        // 3️⃣ Extract form fields (safe extraction)
        $businessName = isset($_POST['business_name']) ? trim((string)$_POST['business_name']) : '';
        $businessType = isset($_POST['business_type']) ? trim((string)$_POST['business_type']) : '';
        $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
        $address = isset($_POST['address']) ? trim((string)$_POST['address']) : '';
        $googleMapLink = isset($_POST['google_map_link']) ? trim((string)$_POST['google_map_link']) : '';

        // 4️⃣ Validate required fields
        if (empty($businessName)) {
            http_response_code(422);
            echo json_encode(['message' => 'Missing required field: business_name']);
            return;
        }

        if (empty($businessType)) {
            http_response_code(422);
            echo json_encode(['message' => 'Missing required field: business_type']);
            return;
        }

        // 5️⃣ Handle image upload (optional)
        $businessImage = null;
        if (isset($_FILES['business_image']) && $_FILES['business_image']['error'] === UPLOAD_ERR_OK) {
            $businessImage = $this->handleImageUpload($_FILES['business_image']);
        }

        // 6️⃣ Prepare update data
        $updateData = [
            'business_name' => $businessName,
            'description' => $description ?: null,
            'business_type' => $businessType,
            'address' => $address ?: null,
            'google_map_link' => $googleMapLink ?: null,
        ];

        // Add image only if uploaded
        if ($businessImage) {
            $updateData['business_image'] = $businessImage;
        }

        // 7️⃣ Update vendor (transaction)
        $db->beginTransaction();
        try {
            $setClause = [];
            $params = ['user_id' => $userId];
            
            foreach ($updateData as $key => $value) {
                $setClause[] = "$key = :$key";
                $params[$key] = $value;
            }
            
            $sql = "UPDATE vendors SET " . implode(', ', $setClause) . " WHERE user_id = :user_id";
            $stmt = $db->prepare($sql);
            $result = $stmt->execute($params);
            
            if (!$result) {
                throw new RuntimeException('Failed to update vendor');
            }
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Vendor profile updated successfully',
            'vendor_id' => $vendor['id'],
            'business_image' => $businessImage ? "/uploads/{$businessImage}" : $updateData['business_image'] ?? null
        ]);

    } catch (RuntimeException $e) {
        http_response_code(500);
        error_log("Update vendor error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    } catch (Exception $e) {
        http_response_code(500);
        error_log("Unexpected vendor update error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    }
}

/**
 * Create vendor profile (status: pending)
 *//**
 * Create vendor profile with image upload (status: pending)
 */
/**
 * Create vendor profile with image upload (status: pending)
 */
public function createVendor(): void
{
    try {
        // 1️⃣ Check authentication
        if (!isset($_SERVER['AUTH_USER'])) {
            http_response_code(401);
            echo json_encode(['message' => 'Authentication required']);
            return;
        }

        $userId = $_SERVER['AUTH_USER']['id'];
        $db = Database::connect();

        // 2️⃣ Check if vendor already exists
        $check = $db->prepare("SELECT id FROM vendors WHERE user_id = :user_id");
        $check->execute(['user_id' => $userId]);
        
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['message' => 'Vendor profile already exists']);
            return;
        }

     // 🔥 3️⃣ HANDLE MULTIPART/FORM-DATA FIELDS - FIXED VERSION
$businessName = isset($_POST['business_name']) ? trim((string)$_POST['business_name']) : '';
$businessType = isset($_POST['business_type']) ? trim((string)$_POST['business_type']) : '';
$description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
$address = isset($_POST['address']) ? trim((string)$_POST['address']) : '';
$googleMapLink = isset($_POST['google_map_link']) ? trim((string)$_POST['google_map_link']) : '';


if (empty($businessName)) {
    http_response_code(422);
    echo json_encode(['message' => 'Missing required field: business_name']);
    return;
}

        // 4️⃣ Validate REQUIRED fields
        if (empty($businessName)) {
            http_response_code(422);
            echo json_encode(['message' => 'Missing required field: business_name']);
            return;
        }
        
        if (empty($businessType)) {
            http_response_code(422);
            echo json_encode(['message' => 'Missing required field: business_type']);
            return;
        }

        // 5️⃣ HANDLE FILE UPLOAD
        $businessImage = null;
        if (isset($_FILES['business_image']) && $_FILES['business_image']['error'] === UPLOAD_ERR_OK) {
            $businessImage = $this->handleImageUpload($_FILES['business_image']);
        }

        // 6️⃣ Prepare vendor data
        $vendorData = [
            'id' => $this->generateUuid(),
            'user_id' => $userId,
            'page_id' => null,
            'slug' => null,
            'business_image' => $businessImage,
            'business_name' => $businessName,
            'description' => $description,
            'business_type' => $businessType,
            'address' => $address,
            'google_map_link' => $googleMapLink ?: null,

        ];

        // 7️⃣ Insert vendor (transaction)
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO vendors (
                    id, user_id, page_id, slug, business_image, 
                    business_name, description, business_type, 
                    address, google_map_link
                ) VALUES (
                    :id, :user_id, :page_id, :slug, :business_image,
                    :business_name, :description, :business_type, 
                    :address, :google_map_link
                )
            ");
            $result = $stmt->execute($vendorData);
            if (!$result) {
                throw new RuntimeException('Failed to create vendor');
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Vendor profile created successfully (pending approval)',
            'vendor_id' => $vendorData['id'],
            'status' => 'pending',
            'business_image' => $businessImage ? "/uploads/{$businessImage}" : null
        ]);

    } catch (RuntimeException $e) {
        http_response_code(500);
        error_log("Create vendor error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    } catch (Exception $e) {
        http_response_code(500);
        error_log("Unexpected vendor creation error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    }
}


/**
 * Handle image upload and save to uploads/ folder
 */
private function handleImageUpload(array $file): ?string
{
    // 1️⃣ Create uploads directory if not exists
    $uploadDir = __DIR__ . '/../../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // 2️⃣ Validate file upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        error_log("Upload error: " . $file['error']);
        return null;
    }

    // 3️⃣ Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        error_log("File too large: " . $file['size']);
        return null;
    }

    // 4️⃣ Validate image types
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mimeType, $allowedTypes)) {
        error_log("Invalid file type: {$mimeType}");
        return null;
    }

    // 5️⃣ Generate unique filename
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newFilename = $this->generateUuid() . '.' . $fileExtension;

    // 6️⃣ Move file to uploads folder
    $targetPath = $uploadDir . $newFilename;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        error_log("Image uploaded: {$newFilename}");
        return $newFilename;
    }

    error_log("Failed to move uploaded file");
    return null;
}

}