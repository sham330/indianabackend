<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use PDO;
use RuntimeException;
use Exception;

class AdminController
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
     * Update user status (Admin only)
     */
    public function updateUserStatus(): void
    {
        try {
            // 1️⃣ Check admin authentication
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || empty($input['user_id']) || empty($input['status'])) {
                http_response_code(422);
                echo json_encode(['message' => 'user_id and status required']);
                return;
            }

            $targetUserId = trim($input['user_id']);
            $newStatus = trim($input['status']);

            // 2️⃣ Validate status enum
            $validStatuses = ['active', 'inactive', 'suspended', 'banned'];
            if (!in_array($newStatus, $validStatuses)) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid status. Must be: ' . implode(', ', $validStatuses)]);
                return;
            }

            $db = Database::connect();

            // 3️⃣ Check if target user exists
            $userCheck = $db->prepare("SELECT id FROM users WHERE id = :user_id");
            $userCheck->execute(['user_id' => $targetUserId]);
            
            if (!$userCheck->fetch()) {
                http_response_code(404);
                echo json_encode(['message' => 'User not found']);
                return;
            }

            // 4️⃣ Update user status
            $stmt = $db->prepare("
                UPDATE users 
                SET status = :status, updated_at = CURRENT_TIMESTAMP
                WHERE id = :user_id
            ");

            $result = $stmt->execute([
                'status' => $newStatus,
                'user_id' => $targetUserId
            ]);

            if (!$result || $stmt->rowCount() === 0) {
                throw new RuntimeException('Failed to update user status');
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => "User status updated to '{$newStatus}' successfully",
                'user_id' => $targetUserId,
                'status' => $newStatus
            ]);

        } catch (RuntimeException $e) {
            http_response_code(500);
            error_log("Update user status error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        } catch (Exception $e) {
            http_response_code(500);
            error_log("Unexpected user status error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * List all users with pagination (Admin only)
     */
    public function listUsers(): void
    {
        try {
            // 1️⃣ Check admin authentication
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $db = Database::connect();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 20;
            $offset = ($page - 1) * $limit;

            // 2️⃣ Get users with pagination
            $stmt = $db->prepare("
                SELECT id, first_name, email, role, status, created_at, updated_at
                FROM users 
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $users = $stmt->fetchAll();

            // 3️⃣ Get total count
            $countStmt = $db->prepare("SELECT COUNT(*) as total FROM users");
            $countStmt->execute();
            $total = (int)$countStmt->fetch()['total'];

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'users' => $users,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'last_page' => ceil($total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("List users error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Get single user details (Admin only)
     */
    public function getUser(): void
    {
        try {
            // 1️⃣ Check admin authentication
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $userId = trim($_GET['user_id'] ?? '');
            if (empty($userId)) {
                http_response_code(422);
                echo json_encode(['message' => 'user_id required']);
                return;
            }

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT id, first_name, last_name, email, role, status, 
                       created_at, updated_at, is_verified
                FROM users 
                WHERE id = :user_id
            ");
            $stmt->execute(['user_id' => $userId]);
            $user = $stmt->fetch();

            if (!$user) {
                http_response_code(404);
                echo json_encode(['message' => 'User not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'user' => $user
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Get user error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Get users by status filter (Admin only)
     */
    public function getUsersByStatus(): void
    {
        try {
            // 1️⃣ Check admin authentication
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $status = trim($_GET['status'] ?? '');
            $validStatuses = ['active', 'inactive', 'suspended', 'banned'];

            if (!empty($status) && !in_array($status, $validStatuses)) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid status filter']);
                return;
            }

            $db = Database::connect();

            $query = "
                SELECT id, first_name, email, role, status, created_at
                FROM users 
                WHERE 1=1
            ";
            $params = [];

            if (!empty($status)) {
                $query .= " AND status = :status";
                $params['status'] = $status;
            }

            $query .= " ORDER BY created_at DESC LIMIT 50";

            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $users = $stmt->fetchAll();

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'users' => $users,
                'filter' => $status ?: 'all'
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Get users by status error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }
}
