<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use PDO;
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

    private function isAdmin(): bool
    {
        return ($_SERVER['AUTH_USER']['role'] ?? '') === 'admin';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREATE PAGE
    // ─────────────────────────────────────────────────────────────────────────

    public function createPage(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId = $_SERVER['AUTH_USER']['id'];
            $db     = Database::connect();

            if (empty($_POST['slug'] ?? '')) {
                http_response_code(422);
                echo json_encode(['message' => 'Missing required field: slug']);
                return;
            }

            $slug           = trim($_POST['slug']);
            $type           = trim($_POST['type'] ?? null); // NEW: type field
            $seoTitle       = trim($_POST['seo_title'] ?? '');
            $seoDescription = trim($_POST['seo_description'] ?? '');

            // Check ad limit (skip for admin)
            if (!$this->isAdmin()) {
                $vendorStmt = $db->prepare("
                    SELECT no_of_ads FROM vendors WHERE user_id = :user_id LIMIT 1
                ");
                $vendorStmt->execute(['user_id' => $userId]);
                $vendor = $vendorStmt->fetch();

                if ($vendor) {
                    $vendorAdLimit = (int)$vendor['no_of_ads'];

                    $pageCountStmt = $db->prepare("
                        SELECT COUNT(*) as page_count FROM html_pages WHERE user_id = :user_id
                    ");
                    $pageCountStmt->execute(['user_id' => $userId]);
                    $pageCount = (int)$pageCountStmt->fetch()['page_count'];

                    if ($pageCount >= $vendorAdLimit) {
                        http_response_code(403);
                        echo json_encode([
                            'message'       => 'Ad limit exceeded',
                            'current_pages' => $pageCount,
                            'max_allowed'   => $vendorAdLimit,
                        ]);
                        return;
                    }
                }
            }

            $finalSlug = $this->generateUniqueSlug($db, $slug);

            $pageData = [
                'id'              => $this->generateUuid(),
                'user_id'         => $userId,
                'vendor_id'       => null,
                'slug'            => $finalSlug,
                'type'            => $type, // NEW: include type
                'html_content'    => null,
                'seo_title'       => $seoTitle,
                'seo_description' => $seoDescription,
            ];

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO html_pages (id, user_id, vendor_id, slug, type, html_content, seo_title, seo_description)
                    VALUES (:id, :user_id, :vendor_id, :slug, :type, :html_content, :seo_title, :seo_description)
                ");
                if (!$stmt->execute($pageData)) {
                    throw new RuntimeException('Failed to create page');
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

            http_response_code(201);
            echo json_encode([
                'success'  => true,
                'message'  => 'HTML page created successfully (content pending)',
                'page_id'  => $pageData['id'],
                'slug'     => $pageData['slug'],
                'type'     => $pageData['type'], // NEW: return type
                'status'   => 'draft',
                'page_url' => "/pages/{$pageData['slug']}",
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'message' => 'Internal server error',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }


    // ─────────────────────────────────────────────────────────────────────────
    // UPLOAD CONTENT  (admin bypasses ownership check)
    // ─────────────────────────────────────────────────────────────────────────

    public function uploadContent(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId      = $_SERVER['AUTH_USER']['id'];
            $isAdmin     = $this->isAdmin();
            $pageId      = trim($_POST['page_id'] ?? '');
            $htmlContent = trim($_POST['html_content'] ?? '');
            $craftState  = $_POST['craft_state'] ?? null;

            if (empty($pageId) || empty($htmlContent)) {
                http_response_code(422);
                echo json_encode(['message' => 'Missing page_id or html_content']);
                return;
            }

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

            // Admin can access any page; regular users only their own
            $checkSql    = $isAdmin
                ? "SELECT id, status FROM html_pages WHERE id = :page_id"
                : "SELECT id, status FROM html_pages WHERE id = :page_id AND user_id = :user_id";
            $checkParams = $isAdmin
                ? ['page_id' => $pageId]
                : ['page_id' => $pageId, 'user_id' => $userId];

            $checkStmt = $db->prepare($checkSql);
            $checkStmt->execute($checkParams);
            $page = $checkStmt->fetch();

            if (!$page) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found or access denied']);
                return;
            }

            $db->beginTransaction();
            try {
                $updateStmt = $db->prepare("
                    UPDATE html_pages
                    SET html_content     = :html_content,
                        screenshot_image = :screenshot_image,
                        craft_state      = :craft_state
                    WHERE id = :page_id
                ");
                if (!$updateStmt->execute([
                    'html_content'     => $htmlContent,
                    'screenshot_image' => $screenshotPath,
                    'craft_state'      => $craftState,
                    'page_id'          => $pageId,
                ])) {
                    throw new RuntimeException('Failed to update page content');
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                if ($screenshotPath !== null) {
                    @unlink(__DIR__ . '/../../uploads/' . $screenshotPath);
                }
                throw $e;
            }

            http_response_code(200);
            echo json_encode([
                'success'             => true,
                'message'             => 'HTML content and screenshot uploaded successfully',
                'page_id'             => $pageId,
                'screenshot_uploaded' => $screenshotPath !== null,
                'screenshot_path'     => $screenshotPath,
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'message' => 'Internal server error',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE PAGE  (admin bypasses ownership check)
    // ─────────────────────────────────────────────────────────────────────────

    public function deletePage(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId  = $_SERVER['AUTH_USER']['id'];
            $isAdmin = $this->isAdmin();
            $pageId  = trim($_POST['page_id'] ?? '');

            if (empty($pageId)) {
                http_response_code(422);
                echo json_encode(['message' => 'Page ID required']);
                return;
            }

            $db = Database::connect();

            $checkSql    = $isAdmin
                ? "SELECT id, screenshot_image FROM html_pages WHERE id = :page_id"
                : "SELECT id, screenshot_image FROM html_pages WHERE id = :page_id AND user_id = :user_id";
            $checkParams = $isAdmin
                ? ['page_id' => $pageId]
                : ['page_id' => $pageId, 'user_id' => $userId];

            $checkStmt = $db->prepare($checkSql);
            $checkStmt->execute($checkParams);
            $page = $checkStmt->fetch();

            if (!$page) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found or access denied']);
                return;
            }

            $db->beginTransaction();
            try {
                $deleteStmt = $db->prepare("DELETE FROM html_pages WHERE id = :page_id");
                $result     = $deleteStmt->execute(['page_id' => $pageId]);

                if (!$result || $deleteStmt->rowCount() === 0) {
                    throw new RuntimeException('Failed to delete page');
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

            if (!empty($page['screenshot_image'])) {
                @unlink(__DIR__ . '/../../uploads/' . $page['screenshot_image']);
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Page deleted successfully',
                'page_id' => $pageId,
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'message' => 'Internal server error',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE STATUS  (admin bypasses ownership check)
    // ─────────────────────────────────────────────────────────────────────────

    public function updateStatus(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId    = $_SERVER['AUTH_USER']['id'];
            $isAdmin   = $this->isAdmin();
            $pageId    = trim($_POST['page_id'] ?? '');
            $newStatus = trim($_POST['status'] ?? '');

            if (empty($pageId) || !in_array($newStatus, ['draft', 'pending', 'published', 'blocked', 'archived'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid page_id or status']);
                return;
            }

            $db = Database::connect();

            $sql    = $isAdmin
                ? "UPDATE html_pages SET status = :status WHERE id = :page_id"
                : "UPDATE html_pages SET status = :status WHERE id = :page_id AND user_id = :user_id";
            $params = $isAdmin
                ? ['status' => $newStatus, 'page_id' => $pageId]
                : ['status' => $newStatus, 'page_id' => $pageId, 'user_id' => $userId];

            $stmt   = $db->prepare($sql);
            $result = $stmt->execute($params);

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
                'status'  => $newStatus,
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'message' => 'Internal server error',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET PAGE CONTENT BY ID  (admin bypasses ownership check)
    // ─────────────────────────────────────────────────────────────────────────

    public function getPageContent(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId  = $_SERVER['AUTH_USER']['id'];
            $isAdmin = $this->isAdmin();
            $pageId  = trim($_GET['page_id'] ?? '');

            if (empty($pageId)) {
                http_response_code(422);
                echo json_encode(['message' => 'Page ID required']);
                return;
            }

            $db = Database::connect();

            $sql    = $isAdmin
                ? "SELECT id, user_id, vendor_id, slug, html_content, seo_title, seo_description,
                          craft_state, status, screenshot_image, created_at, updated_at
                   FROM html_pages WHERE id = :page_id"
                : "SELECT id, user_id, vendor_id, slug, html_content, seo_title, seo_description,
                          craft_state, status, screenshot_image, created_at, updated_at
                   FROM html_pages WHERE id = :page_id AND user_id = :user_id";
            $params = $isAdmin
                ? ['page_id' => $pageId]
                : ['page_id' => $pageId, 'user_id' => $userId];

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$page) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found or access denied']);
                return;
            }

            http_response_code(200);
            echo json_encode(['success' => true, 'page' => $page]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'message' => 'Internal server error',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET PAGE BY SLUG  (public)
    // ─────────────────────────────────────────────────────────────────────────

    public function getPage(): void
    {
        try {
            $slug = trim($_GET['slug'] ?? '');

            if (empty($slug)) {
                http_response_code(422);
                echo json_encode(['message' => 'Slug required']);
                return;
            }

            $db   = Database::connect();
            $stmt = $db->prepare("
                SELECT id, user_id, vendor_id, slug, html_content,
                       seo_title, seo_description, status, screenshot_image
                FROM html_pages
                WHERE slug = :slug AND status IN ('published', 'pending')
            ");
            $stmt->execute(['slug' => $slug]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$page) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found']);
                return;
            }

            http_response_code(200);
            echo json_encode(['success' => true, 'page' => $page]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'message' => 'Internal server error',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LIST OWN PAGES  (authenticated user)
    // ─────────────────────────────────────────────────────────────────────────

    public function listPages(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId = $_SERVER['AUTH_USER']['id'];
            $db     = Database::connect();

            $stmt = $db->prepare("
                SELECT id, vendor_id, slug, seo_title, status,
                       screenshot_image, created_at, updated_at
                FROM html_pages
                WHERE user_id = :user_id
                ORDER BY created_at DESC
            ");
            $stmt->execute(['user_id' => $userId]);
            $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'pages'   => $pages,
                'count'   => count($pages),
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'message' => 'Internal server error',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LIST ALL PAGES  (admin only)
    // ─────────────────────────────────────────────────────────────────────────

 public function listAllPages(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            if (!$this->isAdmin()) {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $db            = Database::connect();
            $status        = trim($_GET['status'] ?? '');
            $type          = trim($_GET['type'] ?? ''); // NEW: type filter
            $validStatuses = ['draft', 'pending', 'published', 'blocked', 'archived'];
            $whereClauses  = [];
            $params        = [];

            if (!empty($status) && in_array($status, $validStatuses)) {
                $whereClauses[] = 'p.status = :status';
                $params['status'] = $status;
            }

            // NEW: type filtering
            if (!empty($type)) {
                $whereClauses[] = 'p.type = :type';
                $params['type'] = $type;
            }

            $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

            $stmt = $db->prepare("
                SELECT
                    p.id,
                    p.slug,
                    p.type, -- NEW: include type
                    p.seo_title,
                    p.seo_description,
                    p.status,
                    p.screenshot_image,
                    p.created_at,
                    p.updated_at,

                    u.id                                   AS user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS user_name,
                    u.email                                AS user_email,
                    u.phone_number                         AS user_phone,

                    v.id                                   AS vendor_id,
                    v.business_name                        AS vendor_business_name,
                    v.business_type                        AS vendor_business_type,
                    v.no_of_ads                            AS vendor_ad_limit,
                    v.status                               AS vendor_status

                FROM html_pages p
                LEFT JOIN users   u ON u.id      = p.user_id
                LEFT JOIN vendors v ON v.user_id = p.user_id

                {$whereClause}
                ORDER BY p.created_at DESC
            ");

            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pages = array_map(function (array $row): array {
                return [
                    'id'               => $row['id'],
                    'slug'             => $row['slug'],
                    'type'             => $row['type'], // NEW: include type
                    'seo_title'        => $row['seo_title'],
                    'seo_description'  => $row['seo_description'],
                    'status'           => $row['status'],
                    'screenshot_image' => $row['screenshot_image'],
                    'created_at'       => $row['created_at'],
                    'updated_at'       => $row['updated_at'],
                    'user' => [
                        'id'    => $row['user_id'],
                        'name'  => $row['user_name'],
                        'email' => $row['user_email'],
                        'phone' => $row['user_phone'],
                    ],
                    'vendor' => $row['vendor_id'] ? [
                        'id'            => $row['vendor_id'],
                        'business_name' => $row['vendor_business_name'],
                        'business_type' => $row['vendor_business_type'],
                        'ad_limit'      => $row['vendor_ad_limit'],
                        'status'        => $row['vendor_status'],
                    ] : null,
                ];
            }, $rows);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'pages'   => $pages,
                'count'   => count($pages),
                // NEW: filter info
                'filters_applied' => [
                    'status' => $status ?: null,
                    'type'   => $type ?: null,
                ],
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'message' => 'Internal server error',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPLOAD IMAGE  (admin bypasses ownership check)
    // ─────────────────────────────────────────────────────────────────────────

    public function uploadImage(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $userId  = $_SERVER['AUTH_USER']['id'];
            $isAdmin = $this->isAdmin();
            $pageId  = trim($_POST['page_id'] ?? '');

            if (!empty($pageId)) {
                $db = Database::connect();

                $checkSql    = $isAdmin
                    ? "SELECT id FROM html_pages WHERE id = :page_id"
                    : "SELECT id FROM html_pages WHERE id = :page_id AND user_id = :user_id";
                $checkParams = $isAdmin
                    ? ['page_id' => $pageId]
                    : ['page_id' => $pageId, 'user_id' => $userId];

                $checkStmt = $db->prepare($checkSql);
                $checkStmt->execute($checkParams);

                if (!$checkStmt->fetch()) {
                    http_response_code(404);
                    echo json_encode(['message' => 'Page not found or access denied']);
                    return;
                }
            }

            if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
                http_response_code(422);
                echo json_encode(['message' => 'No image file provided']);
                return;
            }

            $imagePath = $this->handleImageUpload($_FILES['image']);

            if ($imagePath === null) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid or failed image upload']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success'    => true,
                'message'    => 'Image uploaded successfully',
                'image_path' => $imagePath,
                'image_url'  => $imagePath,
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'message' => 'Internal server error',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

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

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'])) {
            error_log("Invalid file type: {$mimeType}");
            return null;
        }

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $this->generateUuid() . '.' . $ext;

        if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
            return $filename;
        }

        error_log("Failed to move uploaded file");
        return null;
    }

    private function generateUniqueSlug(PDO $pdo, string $baseSlug): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($baseSlug)), '-');

        if (empty($slug)) {
            $slug = 'page-' . time();
        }

        $checkStmt = $pdo->prepare("SELECT id FROM html_pages WHERE slug = :slug");
        $checkStmt->execute(['slug' => $slug]);

        if (!$checkStmt->fetch()) {
            return $slug;
        }

        $counter = 1;
        do {
            $uniqueSlug = $slug . '-' . $counter++;
            $checkStmt->execute(['slug' => $uniqueSlug]);
        } while ($checkStmt->fetch());

        return $uniqueSlug;
    }
}