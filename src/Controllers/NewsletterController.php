<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use RuntimeException;
use Exception;
use src\Core\Mailer;

class NewsletterController
{
    /**
     * Subscribe to newsletter (PUBLIC)
     */
  /**
 * Subscribe to newsletter (PUBLIC) - SENDS CONFIRMATION EMAIL
 */
public function subscribe(): void
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($input['email'])) {
            http_response_code(422);
            echo json_encode(['message' => 'Email required']);
            return;
        }

        $email = strtolower(trim($input['email']));
        $name = trim($input['name'] ?? '');
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $db = Database::connect();

        // 1️⃣ Check if already subscribed
        $checkStmt = $db->prepare("SELECT id, name FROM newsletter_subscribers WHERE email = :email");
        $checkStmt->execute(['email' => $email]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            http_response_code(409);
            echo json_encode(['message' => 'Already subscribed']);
            return;
        }

        // 2️⃣ Insert subscriber
        $stmt = $db->prepare("
            INSERT INTO newsletter_subscribers (email, name, status, ip_address) 
            VALUES (:email, :name, 'subscribed', :ip)
        ");
        
        $result = $stmt->execute([
            'email' => $email,
            'name' => $name ?: 'Newsletter Subscriber',
            'ip' => $ipAddress
        ]);

        if (!$result) {
            throw new RuntimeException('Failed to subscribe');
        }

        // 3️⃣ ✅ SEND CONFIRMATION EMAIL (same style as your AuthController)
        try {
            Mailer::send(
                $email,
                'Welcome to IndianaDesi Newsletter! 🎉',
                "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2 style='color: #333;'>Welcome to IndianaDesi Newsletter!</h2>
                    <p>Hello <strong>" . htmlspecialchars($name ?: 'there') . "</strong>,</p>
                    <p>Thank you for subscribing to our newsletter! 🎉</p>
                    
                    <div style='background: #28a745; color: white; padding: 20px; border-radius: 10px; text-align: center;'>
                        <h3 style='margin: 0;'>You're Officially Subscribed!</h3>
                        <p style='margin: 10px 0 0 0;'>You'll receive our latest updates, deals, and exclusive content.</p>
                    </div>
                    
                    <hr style='border: 1px solid #eee; margin: 30px 0;'>
                    
                    <h4>What to Expect:</h4>
                    <ul style='color: #666;'>
                        <li>Weekly deals and promotions</li>
                        <li>Exclusive vendor offers</li>
                        <li>New product announcements</li>
                        <li>Local business highlights</li>
                    </ul>
                    
                    <p style='color: #666;'>
                        <strong>No spam, ever!</strong> You can unsubscribe anytime using the link at the bottom of any newsletter.
                    </p>
                    
                    <p>Happy shopping!<br>
                    <strong>IndianaDesi Team</strong></p>
                </div>
                "
            );
        } catch (Exception $e) {
            error_log("Newsletter confirmation email failed for {$email}: " . $e->getMessage());
            // ✅ Email failure doesn't block subscription (same as your AuthController)
        }

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Successfully subscribed! Welcome email sent.',
            'subscriber' => ['email' => $email, 'name' => $name ?: 'Newsletter Subscriber']
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        error_log("Subscribe error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    }
}

    /**
     * List subscribers (ADMIN ONLY)
     */
    public function listSubscribers(): void
    {
        try {
            // 1️⃣ Admin check (same as your AuthController)
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $db = Database::connect();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = 50;
            $status = $_GET['status'] ?? null;
            $offset = ($page - 1) * $limit;

            // 2️⃣ Build query with optional status filter
            $whereClause = "WHERE 1=1";
            $params = [];
            
            if ($status && in_array($status, ['subscribed', 'unsubscribed'])) {
                $whereClause .= " AND status = :status";
                $params['status'] = $status;
            }

            $query = "
                SELECT id, email, name, status, subscribed_at, unsubscribed_at, ip_address
                FROM newsletter_subscribers 
                $whereClause 
                ORDER BY subscribed_at DESC 
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $db->prepare($query);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            $stmt->execute();
            $subscribers = $stmt->fetchAll();

            // 3️⃣ Total count
            $countQuery = "SELECT COUNT(*) as total FROM newsletter_subscribers $whereClause";
            $countStmt = $db->prepare($countQuery);
            foreach ($params as $key => $value) {
                $countStmt->bindValue(":$key", $value);
            }
            $countStmt->execute();
            $total = (int)$countStmt->fetch()['total'];

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'subscribers' => $subscribers,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'last_page' => ceil($total / $limit)
                ],
                'filter' => $status ?: 'all'
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("List subscribers error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Update subscriber status (ADMIN ONLY)
     */
    public function updateSubscriber(): void
    {
        try {
            // 1️⃣ Admin check
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || 
                empty($input['id']) || 
                !isset($input['status'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Subscriber ID and status required']);
                return;
            }

            $subscriberId = (int)$input['id'];
            $status = $input['status'];

            if (!in_array($status, ['subscribed', 'unsubscribed'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid status']);
                return;
            }

            $db = Database::connect();

            // 2️⃣ Update status
            $updateStmt = $db->prepare("
                UPDATE newsletter_subscribers 
                SET status = :status" . 
                ($status === 'unsubscribed' ? ', unsubscribed_at = CURRENT_TIMESTAMP' : '') . "
                WHERE id = :id
            ");

            $result = $updateStmt->execute([
                'status' => $status,
                'id' => $subscriberId
            ]);

            if (!$result || $updateStmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'Subscriber not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => "Subscriber status updated to '$status'",
                'subscriber_id' => $subscriberId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Update subscriber error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Delete subscriber (ADMIN ONLY)
     */
    public function deleteSubscriber(): void
    {
        try {
            // 1️⃣ Admin check
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || empty($input['id'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Subscriber ID required']);
                return;
            }

            $subscriberId = (int)$input['id'];
            $db = Database::connect();

            // 2️⃣ Delete subscriber
            $deleteStmt = $db->prepare("DELETE FROM newsletter_subscribers WHERE id = :id");
            $result = $deleteStmt->execute(['id' => $subscriberId]);

            if (!$result || $deleteStmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'Subscriber not found']);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Subscriber deleted successfully',
                'subscriber_id' => $subscriberId
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Delete subscriber error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Export subscribers to CSV (ADMIN ONLY)
     */
    public function exportCsv(): void
    {
        try {
            // 1️⃣ Admin check
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $status = $_GET['status'] ?? null;
            $db = Database::connect();

            $whereClause = "WHERE 1=1";
            $params = [];
            
            if ($status && in_array($status, ['subscribed', 'unsubscribed'])) {
                $whereClause .= " AND status = :status";
                $params['status'] = $status;
            }

            $query = "
                SELECT email, name, status, subscribed_at, ip_address
                FROM newsletter_subscribers 
                $whereClause 
                ORDER BY subscribed_at DESC
            ";

            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            $stmt->execute();
            $subscribers = $stmt->fetchAll();

            // 2️⃣ Generate CSV
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="newsletter_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');
            
            // CSV Header
            fputcsv($output, ['Email', 'Name', 'Status', 'Subscribed At', 'IP Address']);
            
            // CSV Rows
            foreach ($subscribers as $subscriber) {
                fputcsv($output, [
                    $subscriber['email'],
                    $subscriber['name'],
                    $subscriber['status'],
                    $subscriber['subscribed_at'],
                    $subscriber['ip_address']
                ]);
            }
            
            fclose($output);
            exit;

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Export failed']);
        }
    }
}
