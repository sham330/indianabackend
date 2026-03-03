<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use PDO;
use Exception;

class ContactusController
{
    /**
     * Create new contact inquiry (Public - no auth required)
     */
    public function createInquiry(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || 
                empty($input['name']) || empty($input['email']) || empty($input['message'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Name, email, and message are required']);
                return;
            }

            $db = Database::connect();
            
            $stmt = $db->prepare("
                INSERT INTO contact_inquiries (name, phone, email, subject, message, created_at, status) 
                VALUES (:name, :phone, :email, :subject, :message, CURRENT_TIMESTAMP, 'pending')
            ");
            
            $result = $stmt->execute([
                'name' => trim($input['name']),
                'phone' => trim($input['phone'] ?? ''),
                'email' => trim($input['email']),
                'subject' => trim($input['subject'] ?? ''),
                'message' => trim($input['message'])
            ]);

            if ($result) {
                http_response_code(201);
                echo json_encode([
                    'success' => true,
                    'message' => 'Inquiry submitted successfully',
                    'id' => $db->lastInsertId()
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Failed to submit inquiry']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Create inquiry error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * List all inquiries (Admin only)
     */
    public function listInquiries(): void
    {
        $this->checkAdmin();
        
        try {
            $db = Database::connect();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $status = trim($_GET['status'] ?? '');

            $query = "
                SELECT * FROM contact_inquiries 
                WHERE 1=1
            ";
            $params = [];

            if (!empty($status)) {
                $query .= " AND status = :status";
                $params['status'] = $status;
            }

            $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

            $stmt = $db->prepare($query);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            foreach ($params as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            $stmt->execute();
            $inquiries = $stmt->fetchAll();

            $countQuery = "SELECT COUNT(*) as total FROM contact_inquiries";
            if (!empty($status)) {
                $countQuery .= " WHERE status = :status";
            }
            
            $countStmt = $db->prepare($countQuery);
            if (!empty($status)) {
                $countStmt->bindValue(':status', $status);
            }
            $countStmt->execute();
            $total = (int)$countStmt->fetch()['total'];

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'inquiries' => $inquiries,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'last_page' => ceil($total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("List inquiries error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Get single inquiry by ID (Admin only)
     */
    public function getInquiry(): void
    {
        $this->checkAdmin();
        
        try {
            $inquiryId = trim($_GET['id'] ?? '');
            if (empty($inquiryId)) {
                http_response_code(422);
                echo json_encode(['message' => 'Inquiry ID required']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("SELECT * FROM contact_inquiries WHERE id = :id");
            $stmt->execute(['id' => $inquiryId]);
            $inquiry = $stmt->fetch();

            if (!$inquiry) {
                http_response_code(404);
                echo json_encode(['message' => 'Inquiry not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'inquiry' => $inquiry
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Get inquiry error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Update inquiry (Admin only)
     */
    public function updateInquiry(): void
    {
        $this->checkAdmin();
        
        try {
            $inquiryId = trim($_GET['id'] ?? $_POST['id'] ?? '');
            if (empty($inquiryId)) {
                http_response_code(422);
                echo json_encode(['message' => 'Inquiry ID required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid JSON']);
                return;
            }

            $db = Database::connect();
            $updateFields = [];
            $params = ['id' => $inquiryId];
            $allowedFields = ['status', 'subject', 'message'];

            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updateFields[] = "$field = :$field";
                    $params[$field] = $input[$field];
                }
            }

            if (empty($updateFields)) {
                http_response_code(422);
                echo json_encode(['message' => 'No fields to update']);
                return;
            }

            $query = "UPDATE contact_inquiries SET " . implode(', ', $updateFields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->execute($params);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Inquiry updated successfully',
                'inquiry_id' => $inquiryId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Update inquiry error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Update inquiry status (Admin only)
     */
    public function updateInquiryStatus(): void
    {
        $this->checkAdmin();
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || empty($input['id']) || empty($input['status'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Inquiry ID and status required']);
                return;
            }

            $inquiryId = trim($input['id']);
            $status = trim($input['status']);
            $validStatuses = ['pending', 'read', 'replied', 'archived'];

            if (!in_array($status, $validStatuses)) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid status']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("
                UPDATE contact_inquiries 
                SET status = :status, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $result = $stmt->execute([
                'status' => $status,
                'id' => $inquiryId
            ]);

            if (!$result || $stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'Inquiry not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => "Inquiry status updated to '{$status}'",
                'inquiry_id' => $inquiryId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Update inquiry status error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Delete inquiry (Admin only)
     */
    public function deleteInquiry(): void
    {
        $this->checkAdmin();
        
        try {
            $inquiryId = trim($_GET['id'] ?? '');
            if (empty($inquiryId)) {
                http_response_code(422);
                echo json_encode(['message' => 'Inquiry ID required']);
                return;
            }

            $db = Database::connect();
            $stmt = $db->prepare("DELETE FROM contact_inquiries WHERE id = :id");
            $result = $stmt->execute(['id' => $inquiryId]);

            if (!$result || $stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'Inquiry not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Inquiry deleted successfully',
                'inquiry_id' => $inquiryId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Delete inquiry error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Check admin access (private helper)
     */
    private function checkAdmin(): void
    {
        if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['message' => 'Admin access required']);
            exit;
        }
    }
}
