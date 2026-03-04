<?php

declare(strict_types=1);

namespace AttractorPhp\Http;

final class Request
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $parts = parse_url($uri);
        $path = $parts['path'] ?? '/';

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $header = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$header] = $value;
        }

        $body = file_get_contents('php://input') ?: '';
        return new self($method, $path, $query, $headers, $body);
    }

    /** @return array<string, mixed> */
    public function jsonBody(): array
    {
        if ($this->body === '') {
            return [];
        }

        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }

    public function queryBool(string $key, bool $default = false): bool
    {
        $value = $this->query[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower((string) $value);
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }
}
