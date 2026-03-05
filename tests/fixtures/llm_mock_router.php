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
    if (str_contains($normalized, 'strict pipeline validator') || str_contains($normalized, 'validation node:')) {
        return "PASS: validation succeeded for current node output.\n";
    }
    if (str_contains($normalized, 'executing one node of a software-factory pipeline') || str_contains($normalized, 'node id:')) {
        $executionPrompt = $prompt;
        if (preg_match('/Node ID:[\s\S]*$/', $prompt, $scopeMatch) === 1) {
            $executionPrompt = (string) ($scopeMatch[0] ?? $prompt);
        }
        $executionNormalized = strtolower($executionPrompt);

        if (preg_match('/\b([A-Za-z0-9][A-Za-z0-9_\/.-]*\.[A-Za-z][A-Za-z0-9]{0,11})\b/', $executionPrompt, $match) === 1) {
            $path = (string) ($match[1] ?? 'output.txt');
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $content = "Generated artifact.\n";
            if ($extension === 'py') {
                $content = "print(\"Hello, World!\")\n";
            } elseif ($extension === 'js') {
                $content = "console.log(\"Hello, World!\");\n";
            } elseif ($extension === 'md') {
                $content = "# Generated Artifact\n\nHello from the mock LLM.\n";
            }
            return "<<<FILE:{$path}>>>\n{$content}<<<END FILE>>>";
        }

        if (str_contains($executionNormalized, 'javascript') || str_contains($executionNormalized, 'node.js')) {
            return <<<JS
```javascript
console.log("Hello, World!");
```
JS;
        }

        if (str_contains($executionNormalized, 'python')) {
            return <<<PY
```python
print("Hello, World!")
```
PY;
        }

        if (str_contains($executionNormalized, 'svg')) {
            return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 40">
  <text x="8" y="24">generated</text>
</svg>
SVG;
        }
    }
    if (str_contains($normalized, 'executing one node of a software-factory pipeline') || str_contains($normalized, 'node id:')) {
        return "Task completed with concrete output for the current node.\n";
    }
    if (str_contains($normalized, 'svg of a dog')) {
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 120">
  <rect width="240" height="120" fill="#f7fbff"/>
  <circle cx="70" cy="68" r="26" fill="#d8a56d"/>
  <circle cx="150" cy="68" r="24" fill="#d8a56d"/>
  <circle cx="108" cy="56" r="34" fill="#e1b27a"/>
  <circle cx="95" cy="52" r="4" fill="#111"/>
  <circle cx="121" cy="52" r="4" fill="#111"/>
  <ellipse cx="108" cy="66" rx="8" ry="5" fill="#442b18"/>
</svg>
SVG;
    }
    if (str_contains($normalized, 'repair') || str_contains($normalized, 'validation error')) {
        return <<<DOT
digraph fixed_pipeline {
  rankdir=LR;
  start -> repaired;
  repaired -> validate_fix;
  validate_fix -> exit [label="pass"];
  validate_fix -> repaired_retry [label="fail"];
  repaired_retry -> validate_retry -> exit;
}
DOT;
    }
    if (str_contains($normalized, 'modify') || str_contains($normalized, 'changes')) {
        return <<<DOT
digraph iterated_pipeline {
  rankdir=LR;
  start -> update;
  update -> validate_changes;
  validate_changes -> exit [label="pass"];
  validate_changes -> replan [label="fail"];
  replan -> update_retry -> validate_retry -> exit;
}
DOT;
    }
    return <<<DOT
digraph generated_pipeline {
  rankdir=LR;
  start -> plan;
  plan -> implement;
  implement -> validate_initial;
  validate_initial -> proof [label="pass"];
  validate_initial -> rework [label="fail"];
  rework -> implement_retry -> validate_final;
  validate_final -> proof [label="pass"];
  validate_final -> escalation [label="fail"];
  escalation -> proof [label="manual decision"];
}
DOT;
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
