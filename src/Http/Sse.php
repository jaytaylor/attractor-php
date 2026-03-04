<?php

declare(strict_types=1);

namespace AttractorPhp\Http;

final class Sse
{
    /** @param array<string,mixed> $payload */
    public static function frame(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"error":"encoding failure"}';
        }
        return 'data: ' . $json . "\n\n";
    }

    public static function comment(string $message = 'keepalive'): string
    {
        $safe = str_replace(["\r", "\n"], ' ', $message);
        return ': ' . $safe . "\n\n";
    }
}
