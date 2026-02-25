<?php

declare(strict_types=1);

namespace Attractor\LLM\Http;

final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public function header(string $key): ?string
    {
        $lower = strtolower($key);
        foreach ($this->headers as $name => $value) {
            if (strtolower($name) === $lower) {
                return $value;
            }
        }

        return null;
    }
}
