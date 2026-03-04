<?php

declare(strict_types=1);

namespace AttractorPhp\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        $base = ['content-type' => 'application/json; charset=utf-8'];
        return new self($status, array_merge($base, $headers), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        $base = ['content-type' => 'text/plain; charset=utf-8'];
        return new self($status, array_merge($base, $headers), $body);
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        $base = ['content-type' => 'text/html; charset=utf-8'];
        return new self($status, array_merge($base, $headers), $body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
