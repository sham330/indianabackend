<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use PDO;
use Exception;

class HtmlPageMetadataController
{
    /**
     * Update page metadata (Admin or Owner only)
     */
    public function updateMetadata(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Authentication required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || empty($input['page_id'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Page ID required']);
                return;
            }

            $pageId = trim($input['page_id']);
            $db = Database::connect();

            // Check page exists and ownership
            $checkStmt = $db->prepare("SELECT user_id FROM html_pages WHERE id = :id");
            $checkStmt->execute(['id' => $pageId]);
            $page = $checkStmt->fetch();

            if (!$page) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found']);
                return;
            }

            // Check permission (admin or owner)
            $isAdmin = $_SERVER['AUTH_USER']['role'] === 'admin';
            $isOwner = $page['user_id'] === $_SERVER['AUTH_USER']['id'];

            if (!$isAdmin && !$isOwner) {
                http_response_code(403);
                echo json_encode(['message' => 'Access denied']);
                return;
            }

            $updateFields = [];
            $params = ['id' => $pageId];

            if (isset($input['seo_title'])) {
                $updateFields[] = "seo_title = :seo_title";
                $params['seo_title'] = trim($input['seo_title']);
            }

            if (isset($input['seo_description'])) {
                $updateFields[] = "seo_description = :seo_description";
                $params['seo_description'] = trim($input['seo_description']);
            }

            if (isset($input['type'])) {
                $updateFields[] = "type = :type";
                $params['type'] = trim($input['type']);
            }

            if (isset($input['view_count'])) {
                $updateFields[] = "view_count = :view_count";
                $params['view_count'] = (int)$input['view_count'];
            }

            if (empty($updateFields)) {
                http_response_code(422);
                echo json_encode(['message' => 'No fields to update']);
                return;
            }

            $stmt = $db->prepare("
                UPDATE html_pages 
                SET " . implode(', ', $updateFields) . ", updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute($params);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Page metadata updated successfully',
                'page_id' => $pageId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Update page metadata error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Get page metadata by page_id
     */
    public function getMetadata(): void
    {
        try {
            $pageId = trim($_GET['page_id'] ?? '');

            if (empty($pageId)) {
                http_response_code(422);
                echo json_encode(['message' => 'Page ID required']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("
                SELECT id, seo_title, seo_description, type, 
                       COALESCE(view_count, 0) as view_count,
                       status, created_at, updated_at
                FROM html_pages 
                WHERE id = :id
            ");
            $stmt->execute(['id' => $pageId]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$page) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'metadata' => $page
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Get page metadata error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Increment view count (Public)
     */
    public function incrementViewCount(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || empty($input['page_id'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Page ID required']);
                return;
            }

            $pageId = trim($input['page_id']);
            $increment = max(1, (int)($input['view_count'] ?? 1));

            $db = Database::connect();
            
            $stmt = $db->prepare("
                UPDATE html_pages 
                SET view_count = COALESCE(view_count, 0) + :increment
                WHERE id = :id
            ");
            $result = $stmt->execute([
                'increment' => $increment,
                'id' => $pageId
            ]);

            if (!$result || $stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'Page not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'View count incremented',
                'incremented_by' => $increment
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Increment view count error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }
}
