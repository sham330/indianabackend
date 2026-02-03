<?php
declare(strict_types=1);

namespace src\Controllers;

use src\Core\Database;
use src\Core\Mailer;
use Firebase\JWT\JWT;
use Google_Client;

use InvalidArgumentException;
use RuntimeException;
use Exception;

class AuthController
{
    private string $jwtSecret;

    public function __construct()
    {
        $this->jwtSecret = getenv('JWT_SECRET') ?: 'your-super-secret-key-change-this';
    }
public function googleLogin(): void
{
    $clientId = getenv('GOOGLE_CLIENT_ID');
    if (empty($clientId)) {
        http_response_code(500);
        echo json_encode(['message' => 'Google OAuth not configured']);
        return;
    }

    $redirectUri = getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost/indianabackend/auth/google/callback';
    
    // Google OAuth URL
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'openid email profile',
        'response_type' => 'code',
        'access_type' => 'offline'
    ]);
    
    header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
    exit;
}

/**
 * Google Callback - Pure cURL (NO dependencies!)
 */
public function googleCallback(): void
{
    try {
        $code = $_GET['code'] ?? '';
        if (empty($code)) {
            throw new Exception('Authorization code missing');
        }

        $clientId = getenv('GOOGLE_CLIENT_ID');
        $clientSecret = getenv('GOOGLE_CLIENT_SECRET');
        $redirectUri = getenv('GOOGLE_REDIRECT_URI');

        // 1️⃣ Exchange code for tokens (cURL)
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $tokenData = http_build_query([
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $tokenData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $tokenResponse = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);

        $token = json_decode($tokenResponse, true);
        if (!$token || isset($token['error'])) {
            throw new Exception('Token exchange failed: ' . ($token['error_description'] ?? 'Unknown'));
        }

        $idToken = $token['id_token'];

        // 2️⃣ Decode ID Token (no verification needed for basic auth)
        $payload = $this->decodeGoogleIdToken($idToken);
        
        $googleId = $payload['sub'];
        $email = strtolower($payload['email']);
        $name = trim(($payload['given_name'] ?? '') . ' ' . ($payload['family_name'] ?? ''));
        $emailVerified = $payload['email_verified'];

        $db = Database::connect();

        // 3️⃣ Find or create user (SAME LOGIC AS BEFORE)
        $stmt = $db->prepare("
            SELECT id, first_name, role, status, is_verified 
            FROM users 
            WHERE google_id = :google_id OR email = :email
            LIMIT 1
        ");
        $stmt->execute(['google_id' => $googleId, 'email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            // Create new user
            $userId = $this->generateUuid();
            $role = 'user';
            
            $insertStmt = $db->prepare("
                INSERT INTO users (
                    id, first_name, email, google_id, 
                    role, status, is_verified, created_at
                ) VALUES (
                    :id, :name, :email, :google_id,
                    :role, 'active', 1, CURRENT_TIMESTAMP
                )
            ");
            $insertStmt->execute([
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'google_id' => $googleId,
                'role' => $role
            ]);

            $user = ['id' => $userId, 'first_name' => $name, 'role' => $role];
        }

        // 4️⃣ Generate YOUR JWT (same as regular login)
        $payload = [
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24),
            'sub' => $user['id'],
            'name' => $user['first_name'],
            'email' => $email,
            'role' => $user['role']
        ];

        $jwtToken = JWT::encode($payload, $this->jwtSecret, 'HS256');

        // 5️⃣ Redirect to frontend
        $frontendUrl = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
        header("Location: {$frontendUrl}/dashboard?token={$jwtToken}&type=google");
        exit;

    } catch (Exception $e) {
        error_log("Google callback error: " . $e->getMessage());
        $frontendUrl = getenv('FRONTEND_URL') ?: 'http://localhost:3000';
        header("Location: {$frontendUrl}/login?error=google_auth_failed");
        exit;
    }
}
private function decodeGoogleIdToken(string $idToken): array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        throw new Exception('Invalid ID token format');
    }
    
    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]) . '=='), true);
    if (!$payload) {
        throw new Exception('Invalid ID token payload');
    }
    
    return $payload;
}

    /**
     * Register → OTP → Login Flow
     */

public function register(): void
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // 1️⃣ Validate JSON input
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON input');
        }

        // 2️⃣ Basic validation
        if (
            empty($input['first_name']) ||
            empty($input['email']) ||
            empty($input['password'])
        ) {
            http_response_code(422);
            echo json_encode(['message' => 'Required fields missing']);
            return;
        }

        $firstName = trim($input['first_name']);
        $lastName  = trim($input['last_name'] ?? '');
        $email     = strtolower(trim($input['email']));
        $phone     = $input['phone_number'] ?? null;
        $password  = $input['password'];

        // 3️⃣ Role validation
        $role = $input['role'] ?? 'user';
        $allowedRoles = ['admin', 'vendor', 'user'];

        if (!in_array($role, $allowedRoles, true)) {
            http_response_code(400);
            echo json_encode(['message' => 'Invalid role']);
            return;
        }

        // 4️⃣ Hash password
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        if (!$passwordHash) {
            throw new RuntimeException('Failed to hash password');
        }

        $db = Database::connect();

       $checkQuery = "SELECT id, email, phone_number, is_verified FROM users WHERE email = :email OR phone_number = :phone";
$check = $db->prepare($checkQuery);

if (!$check) {
    throw new RuntimeException('Failed to prepare duplicate check query');
}

$check->execute([
    'email' => $email, 
    'phone' => $phone  // ✅ ADDED PHONE CHECK
]);
$existingUser = $check->fetch();


        // ✅ Handle existing users BEFORE OTP generation/insert (NO OTP sent)
        if ($existingUser) {
            http_response_code(409);
            
            if ($existingUser['is_verified'] == 1) {
                echo json_encode([
                    'message' => 'This email is already registered. Please login instead.'
                ]);
            } else {
                echo json_encode([
                    'message' => 'OTP verification pending. Please check your email for OTP or use "Forgot Password" to resend.',
                    'action' => 'otp_pending'
                ]);
            }
            return;
        }

        // 5️⃣ Generate UUID + OTP (NEW user only)
        $userId = $this->generateUuid();
        $otp    = random_int(100000, 999999);

        // 7️⃣ Insert user with transaction (YOUR EXACT structure)
        $db->beginTransaction();
        $userInserted = false;
        
        try {
            $stmt = $db->prepare("
                INSERT INTO users (
                    id, first_name, last_name, email, phone_number,
                    password_hash, role, status, is_verified,
                    otp, otp_expires_at, created_at
                ) VALUES (
                    :id, :first_name, :last_name, :email, :phone,
                    :password, :role, 'inactive', 0,
                    :otp, DATE_ADD(NOW(), INTERVAL 10 MINUTE),
                    NOW()
                )
            ");

            if (!$stmt) {
                throw new RuntimeException('Failed to prepare insert query');
            }

            $result = $stmt->execute([
                'id'          => $userId,
                'first_name'  => $firstName,
                'last_name'   => $lastName,
                'email'       => $email,
                'phone'       => $phone,
                'password'    => $passwordHash,
                'role'        => $role,
                'otp'         => $otp
            ]);

            if (!$result) {
                throw new RuntimeException('Failed to insert user');
            }

            $db->commit();
            $userInserted = true; // ✅ User successfully inserted

        } catch (Exception $e) {
            $db->rollBack();
            throw new RuntimeException('Database insert failed: ' . $e->getMessage());
        }

        // 🚨 8️⃣ Send OTP ONLY if registration succeeded
        if ($userInserted) {
            try {
                Mailer::send(
                    $email,
                    'Verify Your Email - IndianaDesi',
                    "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                        <h2 style='color: #333;'>Email Verification</h2>
                        <p>Hello <strong>{$firstName}</strong>,</p>
                        <p>Your One-Time Password (OTP) is:</p>
                        <div style='background: #007bff; color: white; font-size: 32px; font-weight: bold; 
                                    letter-spacing: 8px; text-align: center; padding: 20px; 
                                    border-radius: 10px; margin: 20px 0;'>{$otp}</div>
                        <p><strong>This OTP is valid for 10 minutes only.</strong></p>
                        <hr style='border: 1px solid #eee;'>
                        <p>If you did not register, please ignore this email.</p>
                        <p>Thank you,<br><strong>IndianaDesi Team</strong></p>
                    </div>
                    "
                );
            } catch (Exception $e) {
                error_log("OTP email failed for user {$userId}: " . $e->getMessage());
                // Email failure logged but doesn't block success response
            }
        }

        // ✅ 9️⃣ Success response ONLY after successful registration
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Registered successfully. Please verify OTP.',
            'user_id' => $userId,
            'role'    => $role
        ]);

    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['message' => $e->getMessage()]);
    } catch (RuntimeException $e) {
        http_response_code(500);
        error_log("Register error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    } catch (Exception $e) {
        http_response_code(500);
        error_log("Unexpected register error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    }
}

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
     * Verify OTP and return JWT token
     */
  /**
 * Verify OTP using EMAIL + OTP (instead of user_id)
 */
public function verifyOtp(): void
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // 1️⃣ Validate input: EMAIL + OTP required
        if (json_last_error() !== JSON_ERROR_NONE || 
            empty($input['email']) || 
            empty($input['otp'])) {
            http_response_code(422);
            echo json_encode(['message' => 'Email and OTP required']);
            return;
        }

        $email = strtolower(trim($input['email']));
        $otp   = (int)$input['otp'];

        $db = Database::connect();
        
        // 2️⃣ Find user by EMAIL
        $stmt = $db->prepare("
            SELECT id, first_name, email, role, otp, otp_expires_at, status, is_verified 
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

        // 3️⃣ Validate OTP
        if ((int)$user['otp'] !== $otp) {
            http_response_code(400);
            echo json_encode(['message' => 'Invalid OTP']);
            return;
        }

        if (strtotime($user['otp_expires_at']) < time()) {
            http_response_code(400);
            echo json_encode(['message' => 'OTP expired']);
            return;
        }

        // 4️⃣ Activate user (set is_verified = 1)
        $update = $db->prepare("
            UPDATE users 
            SET status = 'active', 
                is_verified = 1, 
                otp = NULL, 
                otp_expires_at = NULL 
            WHERE email = :email
        ");
        
        if (!$update->execute(['email' => $email])) {
            throw new RuntimeException('Failed to verify user');
        }

        // 5️⃣ Generate JWT token
        $payload = [
            'iat' => time(),
            'exp' => time() + (60 * (int) (getenv('JWT_EXPIRE_MINUTES') ?: 60)),
            'sub' => $user['id'],
            'name' => $user['first_name'],
            'email' => $user['email'],
            'role' => $user['role']
        ];

        $jwtToken = JWT::encode($payload, $this->jwtSecret, 'HS256');

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Account verified and logged in successfully',
            'token' => $jwtToken,
            'user' => [
                'id' => $user['id'],
                'name' => $user['first_name'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        error_log("OTP verify error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    }
}


    /**
     * Login with email/password → JWT
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
            
            // 1️⃣ Find user
            $stmt = $db->prepare("
                SELECT id, first_name, email, password_hash, role, is_verified, status
                FROM users WHERE email = :email
            ");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Invalid credentials']);
                return;
            }

            // 2️⃣ Check if verified
            if (!$user['is_verified'] || $user['status'] !== 'active') {
                http_response_code(403);
                echo json_encode(['message' => 'Account not verified or inactive']);
                return;
            }

            // 3️⃣ Generate JWT
            $payload = [
                'iat' => time(),
                'exp' => time() + (60 * (int) (getenv('JWT_EXPIRE_MINUTES') ?: 60)),
                'sub' => $user['id'],
                'name' => $user['first_name'],
                'email' => $user['email'],
                'role' => $user['role']
            ];

            $jwtToken = JWT::encode($payload, $this->jwtSecret, 'HS256');

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'token' => $jwtToken,
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['first_name'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Login error: " . $e->getMessage());
            echo json_encode(['message' => 'Internal server error']);
        }
    }


    /**
 * Update user profile (authenticated users only)
 */
public function updateProfile(): void
{
    try {
        // 1️⃣ Check authentication (AuthMiddleware already ran)
        if (!isset($_SERVER['AUTH_USER'])) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            return;
        }

        $userId = $_SERVER['AUTH_USER']['id'];

        // 2️⃣ Get input data
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(422);
            echo json_encode(['message' => 'Invalid JSON input']);
            return;
        }

        // 3️⃣ Define editable fields
        $allowedFields = ['first_name', 'last_name', 'phone_number'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (isset($input[$field]) && !empty(trim($input[$field]))) {
                $updateData[$field] = trim($input[$field]);
            }
        }

        // 4️⃣ Nothing to update?
        if (empty($updateData)) {
            http_response_code(400);
            echo json_encode(['message' => 'No valid fields to update']);
            return;
        }

        // 5️⃣ Update profile in database
        $db = Database::connect();
        $updateStmt = $db->prepare("
            UPDATE users 
            SET " . implode(', ', array_map(fn($key) => "$key = :$key", array_keys($updateData))) . ",
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :user_id
        ");

        $params = array_merge($updateData, ['user_id' => $userId]);
        $result = $updateStmt->execute($params);

        if (!$result || $updateStmt->rowCount() === 0) {
            http_response_code(400);
            echo json_encode(['message' => 'No changes made or user not found']);
            return;
        }

        // 6️⃣ Get updated profile
        $profileStmt = $db->prepare("
            SELECT id, first_name, last_name, email, phone_number, role, status 
            FROM users 
            WHERE id = :user_id
        ");
        $profileStmt->execute(['user_id' => $userId]);
        $updatedProfile = $profileStmt->fetch();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Profile updated successfully',
            'profile' => $updatedProfile
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        error_log("Profile update error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    }
}

/**
 * Resend OTP to user's email
 */
public function resendOtp(): void
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // 1️⃣ Validate input
        if (json_last_error() !== JSON_ERROR_NONE || empty($input['email'])) {
            http_response_code(422);
            echo json_encode(['message' => 'Email required']);
            return;
        }

        $email = strtolower(trim($input['email']));

        $db = Database::connect();
        
        // 2️⃣ Find user by email
        $stmt = $db->prepare("
            SELECT id, first_name, otp, otp_expires_at, is_verified, status 
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

        // 3️⃣ Check if already verified
        if ($user['is_verified']) {
            http_response_code(400);
            echo json_encode(['message' => 'Account already verified']);
            return;
        }

        // 4️⃣ Generate new OTP
        $newOtp = random_int(100000, 999999);
        $firstName = $user['first_name'];

        // 5️⃣ Update OTP in database
        $update = $db->prepare("
            UPDATE users 
            SET otp = :otp, otp_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
            WHERE email = :email
        ");
        
        if (!$update->execute(['otp' => $newOtp, 'email' => $email])) {
            throw new RuntimeException('Failed to update OTP');
        }

        // 6️⃣ Send new OTP email
        try {
            Mailer::send(
                $email,
                'New OTP - IndianaDesi',
                "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2 style='color: #333;'>New OTP Request</h2>
                    <p>Hello <strong>{$firstName}</strong>,</p>
                    <p>Your new One-Time Password (OTP) is:</p>
                    <div style='background: #007bff; color: white; font-size: 32px; font-weight: bold; 
                                letter-spacing: 8px; text-align: center; padding: 20px; 
                                border-radius: 10px; margin: 20px 0;'>{$newOtp}</div>
                    <p><strong>This OTP is valid for 10 minutes only.</strong></p>
                    <hr style='border: 1px solid #eee;'>
                    <p>If you did not request this, please ignore this email.</p>
                    <p>Thank you,<br><strong>IndianaDesi Team</strong></p>
                </div>
                "
            );
        } catch (Exception $e) {
            error_log("Resend OTP email failed for {$email}: " . $e->getMessage());
        }

        // 7️⃣ Success response
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'New OTP sent successfully',
            'email' => $email
        ]);

    } catch (RuntimeException $e) {
        http_response_code(500);
        error_log("Resend OTP error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    } catch (Exception $e) {
        http_response_code(500);
        error_log("Unexpected resend OTP error: " . $e->getMessage());
        echo json_encode(['message' => 'Internal server error']);
    }
}

    /**
     * Get current user profile
     */
/**
 * Get current user profile (uses AuthMiddleware)
 */
public function profile(): void
{
    // 1️⃣ AuthMiddleware already ran and set $_SERVER['AUTH_USER']
    if (!isset($_SERVER['AUTH_USER'])) {
        http_response_code(401);
        echo json_encode(['message' => 'Unauthorized']);
        return;
    }

    $userId = $_SERVER['AUTH_USER']['id'];

    $db = Database::connect();
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, email, role, status, address, date_of_birth, phone_number
        FROM users 
        WHERE id = :id
    ");
    $stmt->execute(['id' => $userId]);
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
}

}
