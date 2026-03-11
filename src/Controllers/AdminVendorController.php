<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use PDO;
use Exception;

class AdminVendorController
{
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
     * List all vendors (Admin only)
     */
    public function listVendors(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $db = Database::connect();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 20;
            $offset = ($page - 1) * $limit;

            $stmt = $db->prepare("
                SELECT v.*, u.first_name, u.email
                FROM vendors v
                LEFT JOIN users u ON v.user_id = u.id
                ORDER BY v.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $vendors = $stmt->fetchAll();

            $countStmt = $db->prepare("SELECT COUNT(*) as total FROM vendors");
            $countStmt->execute();
            $total = (int)$countStmt->fetch()['total'];

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'vendors' => $vendors,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'last_page' => ceil($total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("List vendors error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Get single vendor by ID (Admin only)
     */
    public function getVendor(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $vendorId = trim($_GET['id'] ?? '');
            if (empty($vendorId)) {
                http_response_code(422);
                echo json_encode(['message' => 'Vendor ID required']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("
                SELECT v.*, u.first_name, u.last_name, u.email, u.phone_number
                FROM vendors v
                LEFT JOIN users u ON v.user_id = u.id
                WHERE v.id = :id
            ");
            $stmt->execute(['id' => $vendorId]);
            $vendor = $stmt->fetch();

            if (!$vendor) {
                http_response_code(404);
                echo json_encode(['message' => 'Vendor not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'vendor' => $vendor
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Get vendor error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Get vendors by status (Admin only)
     */
    public function getVendorsByStatus(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $status = trim($_GET['status'] ?? '');
            $validStatuses = ['pending', 'approved', 'rejected', 'reconsider'];

            if (!empty($status) && !in_array($status, $validStatuses)) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid status']);
                return;
            }

            $db = Database::connect();
            $query = "
                SELECT v.*, u.first_name, u.email
                FROM vendors v
                LEFT JOIN users u ON v.user_id = u.id
                WHERE 1=1
            ";
            $params = [];

            if (!empty($status)) {
                $query .= " AND v.status = :status";
                $params['status'] = $status;
            }

            $query .= " ORDER BY v.created_at DESC LIMIT 50";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $vendors = $stmt->fetchAll();

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'vendors' => $vendors,
                'filter' => $status ?: 'all'
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Get vendors by status error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Update vendor with image support (Admin only)
     */
    public function updateVendor(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $vendorId = trim($_GET['id'] ?? $_POST['id'] ?? '');
            if (empty($vendorId)) {
                http_response_code(422);
                echo json_encode(['message' => 'Vendor ID required']);
                return;
            }

            $db = Database::connect();
            $checkStmt = $db->prepare("SELECT business_image FROM vendors WHERE id = :id");
            $checkStmt->execute(['id' => $vendorId]);
            $existingVendor = $checkStmt->fetch();
            
            if (!$existingVendor) {
                http_response_code(404);
                echo json_encode(['message' => 'Vendor not found']);
                return;
            }

            $updateFields = [];
            $params = ['id' => $vendorId];
            $allowedFields = ['business_name', 'description', 'business_type', 'address', 'google_map_link', 'no_of_ads', 'status', 'slug', 'page_id'];

            foreach ($allowedFields as $field) {
                if (isset($_POST[$field])) {
                    $updateFields[] = "$field = :$field";
                    $params[$field] = $_POST[$field];
                }
            }

            // Handle image upload
            if (isset($_FILES['business_image']) && $_FILES['business_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadedFile = $this->handleImageUpload($_FILES['business_image']);
                if ($uploadedFile) {
                    $updateFields[] = "business_image = :business_image";
                    $params['business_image'] = $uploadedFile;
                }
            }

            if (empty($updateFields)) {
                http_response_code(422);
                echo json_encode(['message' => 'No fields to update']);
                return;
            }

            $stmt = $db->prepare("
                UPDATE vendors 
                SET " . implode(', ', $updateFields) . ", updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute($params);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Vendor updated successfully',
                'vendor_id' => $vendorId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Update vendor error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Update vendor status (Admin only)
     */
    public function updateVendorStatus(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || empty($input['id']) || empty($input['status'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Vendor ID and status required']);
                return;
            }

            $vendorId = trim($input['id']);
            $status = trim($input['status']);
            $validStatuses = ['pending', 'approved', 'rejected', 'reconsider'];

            if (!in_array($status, $validStatuses)) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid status']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("
                UPDATE vendors 
                SET status = :status, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $result = $stmt->execute([
                'status' => $status,
                'id' => $vendorId
            ]);

            if (!$result || $stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'Vendor not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => "Vendor status updated to '{$status}'",
                'vendor_id' => $vendorId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Update vendor status error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Update vendor verification status (Admin only)
     */
    public function updateVendorVerification(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || empty($input['vendor_id']) || !isset($input['is_verified'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Vendor ID and is_verified required']);
                return;
            }

            $vendorId = trim($input['vendor_id']);
            $isVerified = (int)$input['is_verified'];

            if (!in_array($isVerified, [0, 1])) {
                http_response_code(422);
                echo json_encode(['message' => 'is_verified must be 0 or 1']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("
                UPDATE vendors 
                SET is_verified = :is_verified, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $result = $stmt->execute([
                'is_verified' => $isVerified,
                'id' => $vendorId
            ]);

            if (!$result || $stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'Vendor not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Vendor verification status updated',
                'vendor_id' => $vendorId,
                'is_verified' => $isVerified
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Update vendor verification error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Delete vendor (Admin only)
     */
    public function deleteVendor(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $vendorId = trim($_GET['id'] ?? '');
            if (empty($vendorId)) {
                http_response_code(422);
                echo json_encode(['message' => 'Vendor ID required']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("DELETE FROM vendors WHERE id = :id");
            $result = $stmt->execute(['id' => $vendorId]);

            if (!$result || $stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'Vendor not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Vendor deleted successfully',
                'vendor_id' => $vendorId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Delete vendor error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Handle image upload
     */
    private function handleImageUpload(array $file): ?string
    {
        $uploadDir = __DIR__ . '/../../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            error_log("Upload error: " . $file['error']);
            return null;
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            error_log("File too large: " . $file['size']);
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mimeType, $allowedTypes)) {
            error_log("Invalid file type: {$mimeType}");
            return null;
        }

        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $newFilename = $this->generateUuid() . '.' . $fileExtension;

        $targetPath = $uploadDir . $newFilename;
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            error_log("Image uploaded: {$newFilename}");
            return $newFilename;
        }

        error_log("Failed to move uploaded file");
        return null;
    }
}
