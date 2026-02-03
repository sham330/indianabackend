<?php
declare(strict_types=1);

namespace src\Core;

use src\Middleware\AuthMiddleware;
use src\Middleware\RoleMiddleware;

class Router
{
    private array $routes = [];

    /* ---------- Route registration ---------- */

    public function get(string $uri, array $action, array $middleware = []): void
    {
        $this->addRoute('GET', $uri, $action, $middleware);
    }

    public function post(string $uri, array $action, array $middleware = []): void
    {
        $this->addRoute('POST', $uri, $action, $middleware);
    }

    public function put(string $uri, array $action, array $middleware = []): void
    {
        $this->addRoute('PUT', $uri, $action, $middleware);
    }

    public function delete(string $uri, array $action, array $middleware = []): void
    {
        $this->addRoute('DELETE', $uri, $action, $middleware);
    }

    private function addRoute(
        string $method,
        string $uri,
        array $action,
        array $middleware
    ): void {
        $this->routes[$method][$uri] = [
            'action'     => $action,
            'middleware' => $middleware
        ];
    }

    /* ---------- Dispatch ---------- */

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = strtok($_SERVER['REQUEST_URI'], '?');

        $route = $this->routes[$method][$uri] ?? null;

        if (!$route) {
            http_response_code(404);
            echo json_encode(['message' => 'Route not found']);
            return;
        }

        // Middleware
        foreach ($route['middleware'] as $mw) {
            if ($mw === 'auth') {
                (new AuthMiddleware())->handle();
            }

            if (str_starts_with($mw, 'role:')) {
                $role = explode(':', $mw)[1];
                (new RoleMiddleware())->handle($role);
            }
        }

        // Controller
        [$class, $methodName] = $route['action'];
        (new $class())->$methodName();
    }
}
