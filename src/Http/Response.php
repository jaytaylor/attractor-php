<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function json(int $status, array $data): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        self::corsHeaders();
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function html(int $status, string $html): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        self::corsHeaders();
        echo $html;
        exit;
    }

    public static function text(int $status, string $text, string $contentType = 'text/plain; charset=utf-8'): never
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        self::corsHeaders();
        echo $text;
        exit;
    }

    public static function file(int $status, string $path, string $contentType): never
    {
        if (!is_file($path)) {
            self::json(404, ['error' => 'not found', 'code' => 'NOT_FOUND']);
        }

        http_response_code($status);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . (string) filesize($path));
        self::corsHeaders();
        readfile($path);
        exit;
    }

    /**
     * @param list<array<string,mixed>> $frames
     */
    public static function sse(array $frames): never
    {
        http_response_code(200);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        self::corsHeaders();

        foreach ($frames as $frame) {
            $payload = json_encode($frame, JSON_UNESCAPED_SLASHES);
            if (!is_string($payload)) {
                continue;
            }
            echo 'data: ' . $payload . "\n\n";
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }

        exit;
    }

    public static function noContent(int $status = 204): never
    {
        http_response_code($status);
        self::corsHeaders();
        exit;
    }

    public static function corsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: GET,POST,DELETE,OPTIONS');
    }
}
