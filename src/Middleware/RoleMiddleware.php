<?php
declare(strict_types=1);

namespace src\Middleware;

class RoleMiddleware
{
    public function handle(string $requiredRoles): void
    {
        if (!isset($_SERVER['AUTH_USER'])) {
            http_response_code(401);
            echo json_encode(['message' => 'Unauthorized']);
            exit;
        }

        $userRole = $_SERVER['AUTH_USER']['role'];
        $allowedRoles = explode('|', $requiredRoles); // "vendor|admin" → ['vendor', 'admin']

        // ✅ Allow if user role matches ANY of the allowed roles
        if (!in_array($userRole, $allowedRoles)) {
            http_response_code(403);
            echo json_encode([
                'message' => "Forbidden. Required role(s): {$requiredRoles}"
            ]);
            exit;
        }
    }
}
