<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small method + path router. Patterns support {param} segments:
 *   $router->get('/api/customers/{id}', fn(array $p) => ...);
 */
final class Router
{
    /** @var array<string, list<array{regex: string, handler: callable}>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void    { $this->add('GET', $path, $handler); }
    public function post(string $path, callable $handler): void   { $this->add('POST', $path, $handler); }
    public function put(string $path, callable $handler): void    { $this->add('PUT', $path, $handler); }
    public function delete(string $path, callable $handler): void { $this->add('DELETE', $path, $handler); }

    public function add(string $method, string $path, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_-]+)', rtrim($path, '/') ?: '/') . '$#';
        $this->routes[$method][] = ['regex' => $regex, 'handler' => $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                ($route['handler'])(array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
                return;
            }
        }

        foreach ($this->routes as $otherMethod => $routes) {
            if ($otherMethod === $method) {
                continue;
            }
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path)) {
                    Response::error(405, 'Method not allowed.');
                    return;
                }
            }
        }

        Response::error(404, 'The page you are looking for does not exist.');
    }
}
