<?php

declare(strict_types=1);

namespace App\Services;

final class DotService
{
    private const PROVIDER_OPENAI = 'openai';
    private const PROVIDER_ANTHROPIC = 'anthropic';
    private const PROVIDER_GEMINI = 'gemini';

    /**
     * @return array{valid:bool, diagnostics:list<array<string,mixed>>}
     */
    public function validate(string $dot): array
    {
        $diagnostics = [];

        if (trim($dot) === '') {
            $diagnostics[] = ['severity' => 'error', 'message' => 'DOT source is empty'];
        }

        if (!preg_match('/\bdigraph\b/i', $dot)) {
            $diagnostics[] = ['severity' => 'error', 'message' => 'DOT must start with digraph'];
        }

        $open = substr_count($dot, '{');
        $close = substr_count($dot, '}');
        if ($open !== $close) {
            $diagnostics[] = ['severity' => 'error', 'message' => 'Unbalanced braces in DOT'];
        }

        if (!preg_match('/[A-Za-z0-9_]+\s*->\s*[A-Za-z0-9_]+/', $dot)) {
            $diagnostics[] = ['severity' => 'error', 'message' => 'DOT must include at least one edge'];
        }

        return [
            'valid' => count($diagnostics) === 0,
            'diagnostics' => $diagnostics,
        ];
    }

    public function renderSvg(string $dot): string
    {
        $command = 'dot -Tsvg';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new DotServiceException('failed to start graphviz renderer', 'DOT_RENDER_FAILED', 500);
        }

        fwrite($pipes[0], $dot);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $svg = is_string($stdout) ? trim($stdout) : '';
        $errorOutput = is_string($stderr) ? trim($stderr) : '';

        if ($exitCode !== 0 || $svg === '') {
            $detail = $errorOutput !== '' ? $errorOutput : 'graphviz returned no output';
            throw new DotServiceException('failed to render dot to svg: ' . $detail, 'DOT_RENDER_FAILED', 400);
        }

        $svgStart = strpos($svg, '<svg');
        if ($svgStart === false) {
            throw new DotServiceException('graphviz output did not contain svg markup', 'DOT_RENDER_FAILED', 400);
        }

        return substr($svg, $svgStart);
    }

    /**
     * @param array{provider?:string,model?:string} $options
     */
    public function generate(string $prompt, array $options = []): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new DotServiceException('prompt is required', 'BAD_REQUEST', 400);
        }

        $operationPrompt = "Task: Generate a Graphviz DOT directed graph for this goal:\n{$prompt}";
        return $this->runOperation('generate', $operationPrompt, $options);
    }

    /**
     * @param array{provider?:string,model?:string} $options
     */
    public function fix(string $dot, string $error, array $options = []): string
    {
        $dot = trim($dot);
        if ($dot === '') {
            throw new DotServiceException('dotSource is required', 'BAD_REQUEST', 400);
        }

        $errorLine = trim($error);
        if ($errorLine === '') {
            $errorLine = 'DOT parser reported invalid graph structure.';
        }

        $operationPrompt = "Task: Repair this invalid DOT graph.\n"
            . "Parser/Error Context: {$errorLine}\n"
            . "Invalid DOT:\n{$dot}";

        return $this->runOperation('fix', $operationPrompt, $options);
    }

    /**
     * @param array{provider?:string,model?:string} $options
     */
    public function iterate(string $baseDot, string $changes, array $options = []): string
    {
        $baseDot = trim($baseDot);
        $changes = trim($changes);

        if ($baseDot === '' || $changes === '') {
            throw new DotServiceException('baseDot and changes are required', 'BAD_REQUEST', 400);
        }

        $operationPrompt = "Task: Apply the requested changes to the DOT graph and return the full updated graph.\n"
            . "Change Request:\n{$changes}\n"
            . "Existing DOT:\n{$baseDot}";

        return $this->runOperation('iterate', $operationPrompt, $options);
    }

    /**
     * @return list<string>
     */
    public function extractStages(string $dot): array
    {
        preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\b\s*->\s*\b([A-Za-z_][A-Za-z0-9_]*)\b/', $dot, $matches, PREG_SET_ORDER);
        $nodes = [];
        foreach ($matches as $m) {
            $from = $m[1] ?? '';
            $to = $m[2] ?? '';
            if ($from !== '' && !in_array($from, $nodes, true)) {
                $nodes[] = $from;
            }
            if ($to !== '' && !in_array($to, $nodes, true)) {
                $nodes[] = $to;
            }
        }

        if ($nodes === []) {
            return ['start', 'done'];
        }

        return $nodes;
    }

    /**
     * @return list<string>
     */
    public function streamChunks(string $dot): array
    {
        $chunks = [];
        $offset = 0;
        while ($offset < strlen($dot)) {
            $chunks[] = substr($dot, $offset, 36);
            $offset += 36;
        }

        return $chunks;
    }

    public function stripCodeFences(string $text): string
    {
        $stripped = preg_replace('/```[a-zA-Z]*\n?|```/', '', $text);
        if (!is_string($stripped)) {
            return $text;
        }

        return trim($stripped);
    }

    /**
     * @param array{provider?:string,model?:string} $options
     */
    private function runOperation(string $operation, string $operationPrompt, array $options): string
    {
        [$provider, $model] = $this->resolveProviderAndModel($options);
        $systemPrompt = $this->dotSystemPrompt();

        $raw = $this->callProvider($provider, $model, $systemPrompt, $operationPrompt);
        $dot = $this->extractDotGraph($raw);
        $validation = $this->validate($dot);
        if ($validation['valid']) {
            return $dot;
        }

        $repairPrompt = "The previous response for {$operation} was invalid DOT.\n"
            . 'Validation diagnostics: ' . $this->diagnosticsSummary($validation['diagnostics']) . "\n"
            . "Previous response:\n{$raw}\n"
            . "Return only corrected DOT.";

        $retryRaw = $this->callProvider($provider, $model, $systemPrompt, $repairPrompt);
        $retryDot = $this->extractDotGraph($retryRaw);
        $retryValidation = $this->validate($retryDot);
        if ($retryValidation['valid']) {
            return $retryDot;
        }

        throw new DotServiceException(
            'provider returned invalid DOT after retry: ' . $this->diagnosticsSummary($retryValidation['diagnostics']),
            'INVALID_DOT',
            502,
        );
    }

    private function dotSystemPrompt(): string
    {
        return "You are a Graphviz DOT generation engine for software workflow pipelines.\n"
            . "Hard requirements:\n"
            . "- Return ONLY DOT source text for a directed graph starting with digraph.\n"
            . "- Do not include markdown, code fences, or commentary.\n"
            . "- The graph must include at least one edge.\n"
            . "- Ensure balanced braces and valid node IDs with letters, numbers, and underscores.\n"
            . "- Include a terminal node named done with shape=Msquare unless an explicit terminal node is already defined.";
    }

    /**
     * @param array{provider?:string,model?:string} $options
     * @return array{0:string,1:string}
     */
    private function resolveProviderAndModel(array $options): array
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? (getenv('DOT_LLM_PROVIDER') ?: self::PROVIDER_OPENAI))));
        $validProviders = [self::PROVIDER_OPENAI, self::PROVIDER_ANTHROPIC, self::PROVIDER_GEMINI];
        if (!in_array($provider, $validProviders, true)) {
            throw new DotServiceException('provider must be one of: openai, anthropic, gemini', 'BAD_REQUEST', 400);
        }

        $model = trim((string) ($options['model'] ?? ''));
        if ($model === '') {
            $model = $this->defaultModelForProvider($provider);
        }

        if ($model === '') {
            throw new DotServiceException('model must be provided or configured for provider', 'BAD_REQUEST', 400);
        }

        return [$provider, $model];
    }

    private function defaultModelForProvider(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_OPENAI => (string) (getenv('DOT_OPENAI_MODEL') ?: 'gpt-5.3-chat-latest'),
            self::PROVIDER_ANTHROPIC => (string) (getenv('DOT_ANTHROPIC_MODEL') ?: 'claude-sonnet-4-6'),
            self::PROVIDER_GEMINI => (string) (getenv('DOT_GEMINI_MODEL') ?: 'gemini-2.5-flash'),
            default => '',
        };
    }

    private function callProvider(string $provider, string $model, string $systemPrompt, string $userPrompt): string
    {
        return match ($provider) {
            self::PROVIDER_OPENAI => $this->callOpenAI($model, $systemPrompt, $userPrompt),
            self::PROVIDER_ANTHROPIC => $this->callAnthropic($model, $systemPrompt, $userPrompt),
            self::PROVIDER_GEMINI => $this->callGemini($model, $systemPrompt, $userPrompt),
            default => throw new DotServiceException('unsupported provider', 'BAD_REQUEST', 400),
        };
    }

    private function callOpenAI(string $model, string $systemPrompt, string $userPrompt): string
    {
        $apiKey = trim((string) getenv('OPENAI_API_KEY'));
        if ($apiKey === '') {
            throw new DotServiceException('OPENAI_API_KEY is not configured', 'PROVIDER_NOT_CONFIGURED', 500);
        }

        $base = trim((string) getenv('OPENAI_BASE_URL'));
        if ($base === '') {
            $base = 'https://api.openai.com';
        }

        $url = $this->joinApiPath($base, '/v1/responses');
        $response = $this->postJson(
            $url,
            [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            [
                'model' => $model,
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [
                            ['type' => 'input_text', 'text' => $systemPrompt],
                        ],
                    ],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'input_text', 'text' => $userPrompt],
                        ],
                    ],
                ],
            ],
            'openai',
        );

        $text = trim((string) ($response['output_text'] ?? ''));
        if ($text !== '') {
            return $text;
        }

        $parts = [];
        $items = $response['output'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $content = $item['content'] ?? [];
                if (!is_array($content)) {
                    continue;
                }
                foreach ($content as $part) {
                    if (!is_array($part)) {
                        continue;
                    }
                    $value = trim((string) ($part['text'] ?? ''));
                    if ($value !== '') {
                        $parts[] = $value;
                    }
                }
            }
        }

        $combined = trim(implode("\n", $parts));
        if ($combined === '') {
            throw new DotServiceException('openai response did not include text content', 'UPSTREAM_INVALID_RESPONSE', 502);
        }

        return $combined;
    }

    private function callAnthropic(string $model, string $systemPrompt, string $userPrompt): string
    {
        $apiKey = trim((string) getenv('ANTHROPIC_API_KEY'));
        if ($apiKey === '') {
            throw new DotServiceException('ANTHROPIC_API_KEY is not configured', 'PROVIDER_NOT_CONFIGURED', 500);
        }

        $base = trim((string) getenv('ANTHROPIC_BASE_URL'));
        if ($base === '') {
            $base = 'https://api.anthropic.com';
        }

        $url = $this->joinApiPath($base, '/v1/messages');
        $response = $this->postJson(
            $url,
            [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'Content-Type: application/json',
            ],
            [
                'model' => $model,
                'max_tokens' => 2_000,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ],
            'anthropic',
        );

        $parts = [];
        $content = $response['content'] ?? [];
        if (is_array($content)) {
            foreach ($content as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (($item['type'] ?? '') !== 'text') {
                    continue;
                }
                $value = trim((string) ($item['text'] ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        $combined = trim(implode("\n", $parts));
        if ($combined === '') {
            throw new DotServiceException('anthropic response did not include text content', 'UPSTREAM_INVALID_RESPONSE', 502);
        }

        return $combined;
    }

    private function callGemini(string $model, string $systemPrompt, string $userPrompt): string
    {
        $apiKey = trim((string) getenv('GEMINI_API_KEY'));
        if ($apiKey === '') {
            $apiKey = trim((string) getenv('GOOGLE_API_KEY'));
        }
        if ($apiKey === '') {
            throw new DotServiceException('GEMINI_API_KEY is not configured', 'PROVIDER_NOT_CONFIGURED', 500);
        }

        $base = trim((string) getenv('GEMINI_BASE_URL'));
        if ($base === '') {
            $base = 'https://generativelanguage.googleapis.com';
        }

        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/' . $model;
        $url = rtrim($base, '/') . '/v1beta/' . rawurlencode($modelPath) . ':generateContent?key=' . rawurlencode($apiKey);
        // rawurlencode encodes '/', but API model paths contain one slash.
        $url = str_replace('%2F', '/', $url);

        $response = $this->postJson(
            $url,
            [
                'Content-Type: application/json',
            ],
            [
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $systemPrompt],
                    ],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $userPrompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                ],
            ],
            'gemini',
        );

        $parts = [];
        $candidates = $response['candidates'] ?? [];
        if (is_array($candidates)) {
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $content = $candidate['content']['parts'] ?? [];
                if (!is_array($content)) {
                    continue;
                }
                foreach ($content as $part) {
                    if (!is_array($part)) {
                        continue;
                    }
                    $value = trim((string) ($part['text'] ?? ''));
                    if ($value !== '') {
                        $parts[] = $value;
                    }
                }
            }
        }

        $combined = trim(implode("\n", $parts));
        if ($combined === '') {
            throw new DotServiceException('gemini response did not include text content', 'UPSTREAM_INVALID_RESPONSE', 502);
        }

        return $combined;
    }

    private function joinApiPath(string $baseUrl, string $defaultPath): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        if (preg_match('#/v1$#', $baseUrl) === 1 && str_starts_with($defaultPath, '/v1/')) {
            return $baseUrl . substr($defaultPath, strlen('/v1'));
        }
        return $baseUrl . $defaultPath;
    }

    /**
     * @param list<string> $headers
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function postJson(string $url, array $headers, array $body, string $provider): array
    {
        if (!function_exists('curl_init')) {
            throw new DotServiceException('curl extension is required for LLM provider calls', 'CONFIG_ERROR', 500);
        }

        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            throw new DotServiceException('failed to encode provider request', 'INTERNAL_ERROR', 500);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new DotServiceException('failed to initialize provider request', 'INTERNAL_ERROR', 500);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if (!is_string($responseBody)) {
            $message = $curlError !== '' ? $curlError : 'provider request failed';
            throw new DotServiceException($provider . ' request failed: ' . $message, 'UPSTREAM_UNREACHABLE', 502);
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $providerMessage = $this->providerErrorMessage($decoded, $responseBody);
            throw new DotServiceException(
                $provider . ' API error (' . $statusCode . '): ' . $providerMessage,
                'UPSTREAM_ERROR',
                502,
            );
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function providerErrorMessage(array $decoded, string $rawBody): string
    {
        $error = $decoded['error'] ?? null;
        if (is_array($error)) {
            $message = trim((string) ($error['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
            $type = trim((string) ($error['type'] ?? ''));
            if ($type !== '') {
                return $type;
            }
        }

        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $raw = trim($rawBody);
        if ($raw === '') {
            return 'unknown provider error';
        }

        return strlen($raw) > 320 ? substr($raw, 0, 320) . '...' : $raw;
    }

    private function extractDotGraph(string $content): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $cleaned = $this->stripCodeFences($normalized);
        $position = stripos($cleaned, 'digraph');
        if ($position === false) {
            throw new DotServiceException('provider response did not include digraph content', 'INVALID_DOT', 502);
        }

        $candidate = trim(substr($cleaned, $position));
        $braceStart = strpos($candidate, '{');
        if ($braceStart === false) {
            return $candidate;
        }

        $depth = 0;
        $end = null;
        $length = strlen($candidate);
        for ($i = $braceStart; $i < $length; $i++) {
            $char = $candidate[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        if ($end !== null) {
            return trim(substr($candidate, 0, $end + 1));
        }

        return $candidate;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     */
    private function diagnosticsSummary(array $diagnostics): string
    {
        $messages = [];
        foreach ($diagnostics as $diagnostic) {
            $message = trim((string) ($diagnostic['message'] ?? ''));
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        return $messages === [] ? 'unknown validation failure' : implode('; ', $messages);
    }
}
