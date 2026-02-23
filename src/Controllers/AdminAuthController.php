<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use src\Core\Mailer;
use Firebase\JWT\JWT;
use Exception;

class AdminAuthController
{
    private string $jwtSecret;

    public function __construct()
    {
        $this->jwtSecret = getenv('JWT_SECRET') ?: 'your-super-secret-key-change-this';
    }

    /**
     * Admin login - Step 1: Verify email & password, send OTP
     */
    public function login(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || 
                empty($input['email']) || 
                empty($input['password'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Email and password required']);
                return;
            }

            $email = strtolower(trim($input['email']));
            $password = $input['password'];

            $db = Database::connect();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = :email AND role = 'admin'");
            $stmt->execute(['email' => $email]);
            $admin = $stmt->fetch();

            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Invalid credentials']);
                return;
            }

            if ($admin['status'] !== 'active') {
                http_response_code(403);
                echo json_encode(['message' => 'Account inactive']);
                return;
            }

            // Generate OTP
            $otp = random_int(100000, 999999);
            
            // Save OTP (no expiry timestamp)
            $updateStmt = $db->prepare("UPDATE users SET otp = :otp WHERE id = :id");
            $updateStmt->execute(['otp' => $otp, 'id' => $admin['id']]);

            // Send OTP email
            try {
                Mailer::send(
                    $email,
                    'Admin Login OTP - IndianaDesi',
                    "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                        <h2 style='color: #333;'>Admin Login Verification</h2>
                        <p>Hello <strong>{$admin['first_name']}</strong>,</p>
                        <p>Your One-Time Password (OTP) for admin login is:</p>
                        <div style='background: #dc3545; color: white; font-size: 32px; font-weight: bold; 
                                    letter-spacing: 8px; text-align: center; padding: 20px; 
                                    border-radius: 10px; margin: 20px 0;'>{$otp}</div>
                        <p><strong>Please use this OTP to complete your login.</strong></p>
                        <hr style='border: 1px solid #eee;'>
                        <p>If you did not attempt to login, please secure your account immediately.</p>
                        <p>Thank you,<br><strong>IndianaDesi Team</strong></p>
                    </div>
                    "
                );
            } catch (Exception $e) {
                error_log("Admin OTP email failed: " . $e->getMessage());
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'OTP sent to your email',
                'email' => $email
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Admin login error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }

    /**
     * Admin login - Step 2: Verify OTP and issue token
     */
    public function verifyOtp(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE || 
                empty($input['email']) || 
                empty($input['otp'])) {
                http_response_code(422);
                echo json_encode(['message' => 'Email and OTP required']);
                return;
            }

            $email = strtolower(trim($input['email']));
            $otp = trim($input['otp']);

            $db = Database::connect();
            $stmt = $db->prepare("
                SELECT * FROM users 
                WHERE email = :email AND role = 'admin' AND status = 'active'
            ");
            $stmt->execute(['email' => $email]);
            $admin = $stmt->fetch();

            if (!$admin) {
                http_response_code(404);
                echo json_encode(['message' => 'Admin not found']);
                return;
            }

            // Verify OTP (convert both to string for comparison)
            if ((string)$admin['otp'] !== (string)$otp) {
                http_response_code(401);
                echo json_encode(['message' => 'Invalid OTP']);
                return;
            }

            // Clear OTP
            $clearStmt = $db->prepare("UPDATE users SET otp = NULL WHERE id = :id");
            $clearStmt->execute(['id' => $admin['id']]);

            // Generate JWT
            $payload = [
                'iat' => time(),
                'exp' => time() + (60 * (int) (getenv('JWT_EXPIRE_MINUTES') ?: 60)),
                'sub' => $admin['id'],
                'name' => $admin['first_name'],
                'email' => $admin['email'],
                'role' => $admin['role']
            ];

            $jwtToken = JWT::encode($payload, $this->jwtSecret, 'HS256');

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Admin login successful',
                'token' => $jwtToken,
                'user' => [
                    'id' => $admin['id'],
                    'name' => $admin['first_name'],
                    'email' => $admin['email'],
                    'role' => $admin['role']
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Admin OTP verify error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }
}
