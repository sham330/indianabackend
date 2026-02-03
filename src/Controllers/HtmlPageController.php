<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use PDO;
use InvalidArgumentException;
use RuntimeException;
use Exception;

class HtmlPageController
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
     * Create HTML page (without html_content initially - status: draft)
     */
    public function createPage(): void
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

            // 2️⃣ Validate required fields (removed html_content)
            $required = ['slug'];
            foreach ($required as $field) {
                if (empty($_POST[$field] ?? '')) {
                    http_response_code(422);
                    echo json_encode(['message' => "Missing required field: {$field}"]);
                    return;
                }
            }

            $slug = trim($_POST['slug']);
            $seoTitle = trim($_POST['seo_title'] ?? '');
            $seoDescription = trim($_POST['seo_description'] ?? '');

            // 3️⃣ Check ad limit using ONLY USER_ID
            $vendorStmt = $db->prepare("
                SELECT no_of_ads 
                FROM vendors 
                WHERE user_id = :user_id
                LIMIT 1
            ");
            $vendorStmt->execute(['user_id' => $userId]);
            $vendor = $vendorStmt->fetch();

            if ($vendor) {
                $vendorAdLimit = (int)$vendor['no_of_ads'];

                $pageCountStmt = $db->prepare("
                    SELECT COUNT(*) as page_count 
                    FROM html_pages 
                    WHERE user_id = :user_id
                ");
                $pageCountStmt->execute(['user_id' => $userId]);
                $pageCount = (int)$pageCountStmt->fetch()['page_count'];

                if ($pageCount >= $vendorAdLimit) {
                    http_response_code(403);
                    echo json_encode([
                        'message' => 'Ad limit exceeded',
                        'current_pages' => $pageCount,
                        'max_allowed' => $vendorAdLimit,
                        'user_id' => $userId
                    ]);
                    return;
                }
            }

            // 4️⃣ Generate unique slug
            $finalSlug = $this->generateUniqueSlug($db, $slug);

            // 5️⃣ Prepare page data (html_content will be NULL initially)
            $pageData = [
                'id' => $this->generateUuid(),
                'user_id' => $userId,
                'vendor_id' => null,
                'slug' => $finalSlug,
                'html_content' => null, // Will be added later via uploadContent
                'seo_title' => $seoTitle,
                'seo_description' => $seoDescription,
            ];

            // 6️⃣ Insert page (status = 'draft' by default)
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO html_pages (
                        id, user_id, vendor_id, slug, 
                        html_content, seo_title, seo_description
                    ) VALUES (
                        :id, :user_id, :vendor_id, :slug,
                        :html_content, :seo_title, :seo_description
                    )
                ");

                $result = $stmt->execute($pageData);
                if (!$result) {
                    throw new RuntimeException('Failed to create page');
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'HTML page created successfully (content pending)',
                'page_id' => $pageData['id'],
                'slug' => $pageData['slug'],
                'status' => 'draft',
                'page_url' => "/pages/{$pageData['slug']}"
            ]);

        } catch (RuntimeException $e) {
            http_response_code(500);
            error_log("Create page error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        } catch (Exception $e) {
            http_response_code(500);
            error_log("Unexpected page creation error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Upload HTML content and screenshot image to existing page
     */
    public function uploadContent(): void
    {
        try {
            // 1️⃣ Check authentication
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId = $_SERVER['AUTH_USER']['id'];

            // 2️⃣ Validate required fields
            $pageId = trim($_POST['page_id'] ?? '');
            $htmlContent = trim($_POST['html_content'] ?? '');

            if (empty($pageId) || empty($htmlContent)) {
                http_response_code(422);
                echo json_encode(['message' => 'Missing page_id or html_content']);
                return;
            }

            // 3️⃣ Handle screenshot image upload (optional)
            $screenshotPath = null;
            if (isset($_FILES['screenshot_image']) && $_FILES['screenshot_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $screenshotPath = $this->handleImageUpload($_FILES['screenshot_image']);
                if ($screenshotPath === null) {
                    http_response_code(422);
                    echo json_encode(['message' => 'Invalid or failed screenshot upload']);
                    return;
                }
            }

            $db = Database::connect();

            // 4️⃣ Verify page ownership
            $checkStmt = $db->prepare("
                SELECT id, status 
                FROM html_pages 
                WHERE id = :page_id AND user_id = :user_id
            ");
            $checkStmt->execute([
                'page_id' => $pageId,
                'user_id' => $userId
            ]);
            $page = $checkStmt->fetch();

            if (!$page) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found or access denied']);
                return;
            }

            // 5️⃣ Update page with HTML content and screenshot
            $db->beginTransaction();
            try {
                $updateStmt = $db->prepare("
                    UPDATE html_pages 
                    SET html_content = :html_content,
                        screenshot_image = :screenshot_image
                    WHERE id = :page_id
                ");

                $result = $updateStmt->execute([
                    'html_content' => $htmlContent,
                    'screenshot_image' => $screenshotPath,
                    'page_id' => $pageId
                ]);

                if (!$result) {
                    throw new RuntimeException('Failed to update page content');
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                
                // Clean up uploaded image if database update fails
                if ($screenshotPath !== null) {
                    $uploadDir = __DIR__ . '/../../uploads/';
                    @unlink($uploadDir . $screenshotPath);
                }
                
                throw $e;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'HTML content and screenshot uploaded successfully',
                'page_id' => $pageId,
                'screenshot_uploaded' => $screenshotPath !== null,
                'screenshot_path' => $screenshotPath
            ]);

        } catch (RuntimeException $e) {
            http_response_code(500);
            error_log("Upload content error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        } catch (Exception $e) {
            http_response_code(500);
            error_log("Unexpected upload error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Handle image upload with validation
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

    /**
     * Generate unique slug
     */
    private function generateUniqueSlug(PDO $pdo, string $baseSlug): string
    {
        // Convert to slug format
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($baseSlug));
        $slug = trim($slug, '-');

        if (empty($slug)) {
            $slug = 'page-' . time();
        }

        // Check if slug exists
        $checkStmt = $pdo->prepare("SELECT id FROM html_pages WHERE slug = :slug");
        $checkStmt->execute(['slug' => $slug]);

        if (!$checkStmt->fetch()) {
            return $slug;
        }

        // Generate unique slug with counter
        $counter = 1;
        do {
            $uniqueSlug = $slug . '-' . $counter;
            $checkStmt->execute(['slug' => $uniqueSlug]);
            $counter++;
        } while ($checkStmt->fetch());

        return $uniqueSlug;
    }

    /**
     * Get page by slug (public endpoint)
     */
    public function getPage(): void
    {
        try {
            $slug = trim($_GET['slug'] ?? '');

            if (empty($slug)) {
                http_response_code(422);
                echo json_encode(['message' => 'Slug required']);
                return;
            }

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT id, user_id, vendor_id, slug, 
                       html_content, seo_title, seo_description, status,
                       screenshot_image
                FROM html_pages 
                WHERE slug = :slug AND status IN ('published', 'pending')
            ");
            $stmt->execute(['slug' => $slug]);
            $page = $stmt->fetch();

            if (!$page) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'page' => $page
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Get page error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * List user's pages (authenticated)
     */
    public function listPages(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId = $_SERVER['AUTH_USER']['id'];
            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT id, vendor_id, slug, seo_title, status, 
                       screenshot_image, created_at, updated_at
                FROM html_pages 
                WHERE user_id = :user_id 
                ORDER BY created_at DESC
            ");
            $stmt->execute(['user_id' => $userId]);
            $pages = $stmt->fetchAll();

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'pages' => $pages,
                'count' => count($pages)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("List pages error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Update page status (admin/vendor only)
     */
    public function updateStatus(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $pageId = trim($_POST['page_id'] ?? '');
            $newStatus = trim($_POST['status'] ?? '');

            if (empty($pageId) || !in_array($newStatus, ['draft', 'pending', 'published', 'blocked'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid page_id or status']);
                return;
            }

            $db = Database::connect();
            
            // Check ownership or admin role
            $userRole = $_SERVER['AUTH_USER']['role'] ?? '';
            $userId = $_SERVER['AUTH_USER']['id'];

            $stmt = $db->prepare("
                UPDATE html_pages 
                SET status = :status 
                WHERE id = :page_id 
                AND (user_id = :user_id OR :user_role = 'admin')
            ");
            
            $result = $stmt->execute([
                'status' => $newStatus,
                'page_id' => $pageId,
                'user_id' => $userId,
                'user_role' => $userRole
            ]);

            if (!$result || $stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found or access denied']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Page status updated successfully',
                'page_id' => $pageId,
                'status' => $newStatus
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Update page status error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Get page content by ID (authenticated - user must own the page)
     */
    public function getPageContent(): void
    {
        try {
            // 1️⃣ Check authentication
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId = $_SERVER['AUTH_USER']['id'];

            // 2️⃣ Validate page_id
            $pageId = trim($_GET['page_id'] ?? '');
            
            if (empty($pageId)) {
                http_response_code(422);
                echo json_encode(['message' => 'Page ID required']);
                return;
            }

            $db = Database::connect();

            // 3️⃣ Fetch page with user verification
            $stmt = $db->prepare("
                SELECT id, user_id, vendor_id, slug, 
                       html_content, seo_title, seo_description, 
                       status, screenshot_image, created_at, updated_at
                FROM html_pages 
                WHERE id = :page_id AND user_id = :user_id
            ");
            
            $stmt->execute([
                'page_id' => $pageId,
                'user_id' => $userId
            ]);
            
            $page = $stmt->fetch();

            // 4️⃣ Check if page exists and belongs to user
            if (!$page) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found or access denied']);
                return;
            }

            // 5️⃣ Return page content
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'page' => $page
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Get page content error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Upload image for page content (authenticated)
     * Returns the image URL/path that can be used in HTML content
     */
    public function uploadImage(): void
    {
        try {
            // 1️⃣ Check authentication
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId = $_SERVER['AUTH_USER']['id'];

            // 2️⃣ Validate page_id (optional - to verify user owns the page)
            $pageId = trim($_POST['page_id'] ?? '');
            
            if (!empty($pageId)) {
                $db = Database::connect();
                
                // Verify page ownership
                $checkStmt = $db->prepare("
                    SELECT id 
                    FROM html_pages 
                    WHERE id = :page_id AND user_id = :user_id
                ");
                $checkStmt->execute([
                    'page_id' => $pageId,
                    'user_id' => $userId
                ]);
                
                if (!$checkStmt->fetch()) {
                    http_response_code(404);
                    echo json_encode(['message' => 'Page not found or access denied']);
                    return;
                }
            }

            // 3️⃣ Validate image file
            if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
                http_response_code(422);
                echo json_encode(['message' => 'No image file provided']);
                return;
            }

            // 4️⃣ Handle image upload
            $imagePath = $this->handleImageUpload($_FILES['image']);
            
            if ($imagePath === null) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid or failed image upload']);
                return;
            }

            // 5️⃣ Return image URL
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'image_path' => $imagePath,
                'image_url' => "/uploads/{$imagePath}"
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Upload image error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }
}