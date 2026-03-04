<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,mixed> $entry */
function logRequest(array $entry): void
{
    $logPath = (string) (getenv('ATTRACTOR_MOCK_LLM_LOG') ?: '');
    if ($logPath === '') {
        return;
    }

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }
    file_put_contents($logPath, $line . "\n", FILE_APPEND);
}

function extractPrompt(array $body): string
{
    if (isset($body['messages']) && is_array($body['messages'])) {
        $texts = [];
        foreach ($body['messages'] as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_string($content) && trim($content) !== '') {
                $texts[] = trim($content);
                continue;
            }
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $text = trim((string) ($part['text'] ?? ''));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }
        return implode("\n", $texts);
    }

    if (isset($body['input']) && is_array($body['input'])) {
        $texts = [];
        foreach ($body['input'] as $message) {
            if (!is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? [];
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $text = trim((string) ($part['text'] ?? ''));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }
        return implode("\n", $texts);
    }

    return '';
}

function buildDot(string $prompt): string
{
    $normalized = strtolower($prompt);
    if (str_contains($normalized, 'repair') || str_contains($normalized, 'validation error')) {
        return "digraph fixed_pipeline {\n  start -> repaired;\n  repaired -> exit;\n}\n";
    }
    if (str_contains($normalized, 'modify') || str_contains($normalized, 'changes')) {
        return "digraph iterated_pipeline {\n  start -> update;\n  update -> exit;\n}\n";
    }
    return "digraph generated_pipeline {\n  start -> plan;\n  plan -> implement;\n  implement -> exit;\n}\n";
}

function respondJson(int $status, array $payload): void
{
    http_response_code($status);
    header('content-type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
}

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path === '/health') {
    respondJson(200, ['ok' => true]);
    return;
}

$body = readJsonBody();
$prompt = extractPrompt($body);

if ($path === '/openai/v1/responses') {
    $dot = buildDot($prompt);
    logRequest([
        'provider' => 'openai',
        'path' => $path,
        'prompt' => $prompt,
        'body' => $body,
    ]);
    respondJson(200, [
        'id' => 'resp_mock_openai',
        'output_text' => $dot,
    ]);
    return;
}

if ($path === '/anthropic/v1/messages') {
    $dot = buildDot($prompt);
    logRequest([
        'provider' => 'anthropic',
        'path' => $path,
        'prompt' => $prompt,
        'body' => $body,
    ]);
    respondJson(200, [
        'id' => 'msg_mock_anthropic',
        'type' => 'message',
        'content' => [
            ['type' => 'text', 'text' => $dot],
        ],
    ]);
    return;
}

respondJson(404, ['error' => 'not found']);
