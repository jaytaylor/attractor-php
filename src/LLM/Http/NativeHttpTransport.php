<?php

declare(strict_types=1);

namespace Attractor\LLM\Http;

final class NativeHttpTransport implements HttpTransport
{
    public function __construct(
        private readonly int $timeoutMs = 30_000,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($request->method),
                'header' => implode("\r\n", $headers),
                'content' => $request->body,
                'ignore_errors' => true,
                'timeout' => max(1, (int) ceil($this->timeoutMs / 1000)),
            ],
        ]);

        $warning = null;
        set_error_handler(static function (int $_severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $body = file_get_contents($request->url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($body === false) {
            throw new \RuntimeException('http request failed: ' . ($warning ?? 'unknown transport error'));
        }

        /** @var list<string> $httpResponseHeader */
        $httpResponseHeader = is_array(http_get_last_response_headers()) ? http_get_last_response_headers() : [];

        $statusCode = 0;
        $parsedHeaders = [];

        foreach ($httpResponseHeader as $index => $line) {
            if ($index === 0) {
                if (preg_match('/\s(\d{3})\s/', $line, $matches) === 1) {
                    $statusCode = (int) $matches[1];
                }
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if ($name === '') {
                continue;
            }

            if (isset($parsedHeaders[$name])) {
                $parsedHeaders[$name] .= ', ' . $value;
            } else {
                $parsedHeaders[$name] = $value;
            }
        }

        return new HttpResponse($statusCode > 0 ? $statusCode : 500, $parsedHeaders, $body);
    }
}
