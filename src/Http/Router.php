<?php

declare(strict_types=1);

namespace AttractorPhp\Http;

use Closure;

final class Router
{
    /** @var array<int, array{method:string,pattern:string,regex:string,handler:Closure}> */
    private array $routes = [];

    public function add(string $method, string $pattern, Closure $handler): void
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z0-9_]+)(\+)?\}#',
            static function (array $match): string {
                $name = (string) ($match[1] ?? '');
                $isCatchAll = ($match[2] ?? '') === '+';
                return $isCatchAll ? '(?P<' . $name . '>.+)' : '(?P<' . $name . '>[^/]+)';
            },
            $pattern
        );
        $regex = '#^' . $regex . '$#';
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => (string) $regex,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): ?Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode((string) $value);
                }
            }

            return ($route['handler'])($request, $params);
        }

        return null;
    }
}
