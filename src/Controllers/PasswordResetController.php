<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use src\Core\Mailer;
use InvalidArgumentException;
use RuntimeException;
use Exception;

class PasswordResetController
{
    /**
     * Send OTP to user's email for password reset (PUBLIC)
     */
    public function sendResetOtp(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || empty($input['email'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Email required']);
                return;
            }

            $email = strtolower(trim($input['email']));
            $db = Database::connect();

            // 1️⃣ Check if user exists
            $checkStmt = $db->prepare("SELECT id, first_name FROM users WHERE email = :email");
            $checkStmt->execute(['email' => $email]);
            $user = $checkStmt->fetch();

            if (!$user) {
                http_response_code(404);
                echo json_encode(['message' => 'Email not found']);
                return;
            }

            // 2️⃣ Generate OTP
            $otp = random_int(100000, 999999);

            // 3️⃣ Update user with OTP (expires in 10 mins)
            $db->beginTransaction();
            try {
                $updateStmt = $db->prepare("
                    UPDATE users 
                    SET reset_otp = :otp, 
                        reset_otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
                    WHERE email = :email
                ");
                
                $result = $updateStmt->execute([
                    'otp' => $otp,
                    'email' => $email
                ]);

                $db->commit();

                if (!$result) {
                    throw new RuntimeException('Failed to save OTP');
                }
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

            // 4️⃣ Send OTP email
            try {
                Mailer::send(
                    $email,
                    'Password Reset OTP - IndianaDesi',
                    "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                        <h2 style='color: #333;'>Password Reset Request</h2>
                        <p>Hello <strong>{$user['first_name']}</strong>,</p>
                        <p>You requested to reset your password. Your OTP is:</p>
                        <div style='background: #ff6b35; color: white; font-size: 32px; font-weight: bold; 
                                    letter-spacing: 8px; text-align: center; padding: 20px; 
                                    border-radius: 10px; margin: 20px 0;'>{$otp}</div>
                        <p><strong>This OTP expires in 10 minutes.</strong></p>
                        <p>If you did not request this, please ignore this email.</p>
                        <p>Thank you,<br><strong>IndianaDesi Team</strong></p>
                    </div>
                    "
                );
            } catch (Exception $e) {
                error_log("Reset OTP email failed for {$email}: " . $e->getMessage());
                // Don't block on email failure
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Password reset OTP sent to your email'
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Send reset OTP error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Verify OTP and set new password (PUBLIC)
     */
    public function verifyResetOtp(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || 
                empty($input['email']) || 
                empty($input['otp']) || 
                empty($input['password'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Email, OTP, and new password required']);
                return;
            }

            $email = strtolower(trim($input['email']));
            $otp = (int)$input['otp'];
            $newPassword = $input['password'];

            $db = Database::connect();

            // 1️⃣ Find user with OTP
            $stmt = $db->prepare("
                SELECT id, first_name, reset_otp, reset_otp_expires_at 
                FROM users 
                WHERE email = :email
            ");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                http_response_code(404);
                echo json_encode(['message' => 'User not found']);
                return;
            }

            // 2️⃣ Validate OTP
            if ((int)$user['reset_otp'] !== $otp) {
                http_response_code(400);
                echo json_encode(['message' => 'Invalid OTP']);
                return;
            }

            if (strtotime($user['reset_otp_expires_at']) < time()) {
                http_response_code(400);
                echo json_encode(['message' => 'OTP expired']);
                return;
            }

            // 3️⃣ Hash new password
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            if (!$passwordHash) {
                throw new RuntimeException('Failed to hash password');
            }

            // 4️⃣ Update password and clear OTP
            $updateStmt = $db->prepare("
                UPDATE users 
                SET password_hash = :password_hash,
                    reset_otp = NULL,
                    reset_otp_expires_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE email = :email
            ");

            $result = $updateStmt->execute([
                'password_hash' => $passwordHash,
                'email' => $email
            ]);

            if (!$result) {
                throw new RuntimeException('Failed to update password');
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Password reset successfully. Please login with new password.'
            ]);

        } catch (RuntimeException $e) {
            http_response_code(500);
            error_log("Reset password error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        } catch (Exception $e) {
            http_response_code(500);
            error_log("Unexpected reset error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }
}
