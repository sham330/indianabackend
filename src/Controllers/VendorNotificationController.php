<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use src\Core\Mailer;
use Exception;

class VendorNotificationController
{
    /**
     * Send HTML page status notification email (Admin only)
     */
    public function sendStatusNotification(): void
    {
        try {
            if (!isset($_SERVER['AUTH_USER']) || $_SERVER['AUTH_USER']['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['message' => 'Admin access required']);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || 
                empty($input['id']) || 
                empty($input['status'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Page ID and status required']);
                return;
            }

            $pageId = trim($input['id']);
            $status = trim($input['status']);
            $notes = trim($input['notes'] ?? '');

            if (!in_array($status, ['approved', 'rejected', 'draft'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid status. Must be: approved, rejected, or draft']);
                return;
            }

            if (in_array($status, ['rejected', 'draft']) && empty($notes)) {
                http_response_code(422);
                echo json_encode(['message' => 'Notes required for rejected or draft status']);
                return;
            }

            $db = Database::connect();
            
            // Fetch page and user data
            $stmt = $db->prepare("
                SELECT hp.seo_title, hp.user_id, u.first_name, u.email
                FROM html_pages hp
                JOIN users u ON hp.user_id = u.id
                WHERE hp.id = :id
            ");
            $stmt->execute(['id' => $pageId]);
            $data = $stmt->fetch();

            if (!$data) {
                http_response_code(404);
                echo json_encode(['message' => 'HTML page not found']);
                return;
            }

            $name = $data['first_name'];
            $email = $data['email'];
            $pageTitle = $data['seo_title'];

            $subject = $this->getEmailSubject($status, $pageTitle);
            $body = $this->getEmailBody($name, $status, $notes, $pageTitle);

            $mailer = new Mailer();
            $result = $mailer->send($email, $subject, $body);

            if ($result) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => "Status notification sent to {$email}",
                    'status' => $status,
                    'page_title' => $pageTitle
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['message' => 'Failed to send email']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Send page notification error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    private function getEmailSubject(string $status, string $pageTitle): string
    {
        return match($status) {
            'approved' => "Congratulations! Your Page '{$pageTitle}' is Approved",
            'rejected' => "Update Required: Your Page '{$pageTitle}'",
            'draft' => "Action Required: Complete Your Page '{$pageTitle}'",
            default => 'Page Status Update'
        };
    }

    private function getEmailBody(string $name, string $status, string $notes, string $pageTitle): string
    {
        $baseStyle = 'font-family: Arial, sans-serif; line-height: 1.6; color: #333;';
        
        if ($status === 'approved') {
            return "
                <div style='{$baseStyle}'>
                    <h2 style='color: #28a745;'>Congratulations, {$name}!</h2>
                    <p>We are pleased to inform you that your page <strong>{$pageTitle}</strong> has been <strong>approved</strong>.</p>
                    <p>Your page is now live and accessible to visitors.</p>
                    <p>Thank you for your contribution!</p>
                    <hr style='border: 1px solid #eee; margin: 20px 0;'>
                    <p style='color: #666; font-size: 12px;'>If you have any questions, please contact our support team.</p>
                </div>
            ";
        }

        if ($status === 'rejected') {
            return "
                <div style='{$baseStyle}'>
                    <h2 style='color: #dc3545;'>Page Update Required</h2>
                    <p>Dear {$name},</p>
                    <p>Your page <strong>{$pageTitle}</strong> requires some updates before approval.</p>
                    <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #dc3545; margin: 20px 0;'>
                        <strong>Reason:</strong>
                        <p>{$notes}</p>
                    </div>
                    <p>Please review the feedback above and resubmit your page with the necessary changes.</p>
                    <hr style='border: 1px solid #eee; margin: 20px 0;'>
                    <p style='color: #666; font-size: 12px;'>If you need assistance, please contact our support team.</p>
                </div>
            ";
        }

        if ($status === 'draft') {
            return "
                <div style='{$baseStyle}'>
                    <h2 style='color: #ffc107;'>Complete Your Page</h2>
                    <p>Dear {$name},</p>
                    <p>Your page <strong>{$pageTitle}</strong> is currently in <strong>draft</strong> status and requires completion.</p>
                    <div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>
                        <strong>Action Required:</strong>
                        <p>{$notes}</p>
                    </div>
                    <p>Please complete your page to proceed with the approval process.</p>
                    <hr style='border: 1px solid #eee; margin: 20px 0;'>
                    <p style='color: #666; font-size: 12px;'>If you need help, please contact our support team.</p>
                </div>
            ";
        }

        return "<p>Status update for {$name}</p>";
    }
}
