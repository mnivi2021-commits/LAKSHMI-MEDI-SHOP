<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Small method + path router with named middleware.
 *
 *   $router->middleware('auth', fn(?string $arg): bool => ...);   // return false = stop (middleware already responded)
 *   $router->get('/api/customers/{id}', fn(array $p) => ..., ['api_auth', 'can:customers.view']);
 */
final class Router
{
    /** @var array<string, list<array{regex: string, handler: callable, middleware: list<string>}>> */
    private array $routes = [];

    /** @var array<string, callable(?string): bool> */
    private array $middleware = [];

    public function middleware(string $name, callable $fn): void
    {
        $this->middleware[$name] = $fn;
    }

    /** @param list<string> $middleware */
    public function get(string $path, callable $handler, array $middleware = []): void    { $this->add('GET', $path, $handler, $middleware); }
    /** @param list<string> $middleware */
    public function post(string $path, callable $handler, array $middleware = []): void   { $this->add('POST', $path, $handler, $middleware); }
    /** @param list<string> $middleware */
    public function put(string $path, callable $handler, array $middleware = []): void    { $this->add('PUT', $path, $handler, $middleware); }
    /** @param list<string> $middleware */
    public function delete(string $path, callable $handler, array $middleware = []): void { $this->add('DELETE', $path, $handler, $middleware); }

    /** @param list<string> $middleware */
    public function add(string $method, string $path, callable $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_-]+)', rtrim($path, '/') ?: '/') . '$#';
        $this->routes[$method][] = ['regex' => $regex, 'handler' => $handler, 'middleware' => $middleware];
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            foreach ($route['middleware'] as $spec) {
                // "can:users.view" -> middleware "can" with argument "users.view"
                [$name, $arg] = array_pad(explode(':', $spec, 2), 2, null);
                $fn = $this->middleware[$name] ?? throw new InvalidArgumentException("Unknown middleware: {$name}");
                if ($fn($arg) === false) {
                    return;
                }
            }
            ($route['handler'])(array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            return;
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
