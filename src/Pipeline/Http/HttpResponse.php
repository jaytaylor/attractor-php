<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Http;

final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = ['Content-Type' => 'application/json'],
    ) {
    }
}
