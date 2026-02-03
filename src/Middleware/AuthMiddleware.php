<?php
declare(strict_types=1);

namespace src\Middleware;

use src\Core\Database;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware
{
    public function handle(): void
    {
        $headers = getallheaders();

        if (!isset($headers['Authorization'])) {
            http_response_code(401);
            echo json_encode(['message' => 'Authorization header missing']);
            exit;
        }

        // Expected: Authorization: Bearer <jwt-token>
        $authHeader = $headers['Authorization'];
        if (!str_starts_with($authHeader, 'Bearer ')) {
            http_response_code(401);
            echo json_encode(['message' => 'Invalid authorization format']);
            exit;
        }

        $token = trim(str_replace('Bearer ', '', $authHeader));
        if ($token === '') {
            http_response_code(401);
            echo json_encode(['message' => 'Invalid token']);
            exit;
        }

        try {
            // 🔥 JWT VERIFICATION
            $secret = getenv('JWT_SECRET') ?: 'your-super-secret-key-change-this';
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $userData = (array) $decoded;

            // Validate required JWT claims
            if (!isset($userData['sub']) || !isset($userData['role'])) {
                http_response_code(401);
                echo json_encode(['message' => 'Invalid token structure']);
                exit;
            }

            $db = Database::connect();

            // Verify user exists and is active
            $stmt = $db->prepare("
                SELECT id, role, status
                FROM users
                WHERE id = :id AND status = 'active' AND is_verified = 1
            ");

            $stmt->execute(['id' => $userData['sub']]);
            $user = $stmt->fetch();

            if (!$user || $user['role'] !== $userData['role']) {
                http_response_code(401);
                echo json_encode(['message' => 'Unauthorized']);
                exit;
            }

        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(['message' => 'Invalid token']);
            exit;
        }

        // ✅ Store authenticated user info globally (SAME AS BEFORE)
        $_SERVER['AUTH_USER'] = [
            'id'   => $user['id'],
            'role' => $user['role']
        ];
    }
}
