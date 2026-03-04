<?php

declare(strict_types=1);

namespace AttractorPhp\Llm;

use AttractorPhp\Domain\DotGraphParser;
use AttractorPhp\Domain\DotService;
use AttractorPhp\Http\ApiError;

final class DotLlmService
{
    private ?string $cachedGenerationExamples = null;
    private readonly DotGraphParser $graphParser;

    public function __construct(private readonly DotService $dotService, ?DotGraphParser $graphParser = null)
    {
        $this->graphParser = $graphParser ?? new DotGraphParser();
    }

    /** @param array<string,mixed> $options */
    public function generateFromPrompt(string $prompt, array $options = []): string
    {
        $userPrompt = "Create a Graphviz DOT digraph for this request:\n" . trim($prompt);
        return $this->requestValidDot($userPrompt, $options, true);
    }

    /** @param array<string,mixed> $options */
    public function fixDot(string $dotSource, string $error, array $options = []): string
    {
        $details = trim($error);
        if ($details === '') {
            $details = 'DOT validation failed';
        }

        $userPrompt = "Repair this DOT digraph.\nValidation error:\n{$details}\n\nCurrent DOT:\n{$dotSource}";
        return $this->requestValidDot($userPrompt, $options, false);
    }

    /** @param array<string,mixed> $options */
    public function iterateDot(string $baseDot, string $changes, array $options = []): string
    {
        $userPrompt = "Modify this DOT digraph using the requested changes.\nChanges:\n" . trim($changes) . "\n\nCurrent DOT:\n{$baseDot}";
        return $this->requestValidDot($userPrompt, $options, false);
    }

    /** @param array<string,mixed> $options */
    private function requestValidDot(string $userPrompt, array $options, bool $isGenerationRequest): string
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(240);
        }

        $systemPrompt = implode("\n", [
            'You are a Graphviz DOT expert.',
            'Return only raw DOT source for exactly one digraph.',
            'Do not include markdown fences or prose.',
            'Ensure braces are balanced and all edges have valid targets.',
            'Generated workflows must model validation explicitly.',
            'Include at least one validation node plus pass/fail branching.',
            'The fail branch must kick work back to planning or implementation and then return through a follow-up validation node.',
            'Use sensible retry loops: fail -> rework -> revised artifact -> follow-up validation.',
            'If a Draft node exists, retry/fail loops should route back through Draft (or RevisedDraft) before re-validation.',
            'Avoid trivial loops that jump from fail directly back to the same validator without substantive rework.',
            'If the request asks for SVG, image, or drawing content, still respond with DOT that models the concept as a graph.',
        ]);
        if ($isGenerationRequest) {
            $examples = $this->generationExamplesPrompt();
            if ($examples !== '') {
                $systemPrompt .= "\n\nUse these high-quality DOT examples as style and structure references:\n\n" . $examples;
            }
        }

        $diagnosticsHint = '';
        $lastCompletion = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $completion = $this->complete($systemPrompt, $userPrompt . $diagnosticsHint, $options);
            $lastCompletion = $completion;
            $normalized = $this->normalizeCandidate($completion, $userPrompt);
            $validation = $this->dotService->validate($normalized);
            if ((bool) $validation['valid']) {
                $candidate = (string) $validation['dotSource'];
                if ($isGenerationRequest) {
                    $qualityDiagnostics = $this->generationQualityDiagnostics($candidate);
                    if ($qualityDiagnostics !== []) {
                        $diagnosticsHint = "\n\nThe previous output was syntactically valid but failed workflow quality checks. Fix these issues:\n- " . implode("\n- ", $qualityDiagnostics);
                        continue;
                    }
                }
                return $candidate;
            }

            $messages = array_map(
                static fn(array $diag): string => (string) ($diag['message'] ?? 'invalid DOT'),
                (array) ($validation['diagnostics'] ?? [])
            );
            $diagnosticsHint = "\n\nThe previous output was invalid. Fix these issues:\n- " . implode("\n- ", $messages);
        }

        $fallback = $this->dotService->fallbackFromPrompt($userPrompt);
        $fallbackValidation = $this->dotService->validate($fallback);
        if ((bool) $fallbackValidation['valid']) {
            return (string) $fallbackValidation['dotSource'];
        }

        throw new ApiError(502, 'INVALID_PROVIDER_RESPONSE', 'provider returned DOT that failed validation');
    }

    /** @return list<string> */
    private function generationQualityDiagnostics(string $dotSource): array
    {
        try {
            $graph = $this->graphParser->parse($dotSource);
        } catch (\Throwable) {
            return ['Unable to parse generated DOT graph for workflow quality checks.'];
        }

        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $outgoing = is_array($graph['outgoing'] ?? null) ? $graph['outgoing'] : [];
        $diagnostics = [];
        $validationNodeIds = [];
        $reworkNodeIds = [];
        $draftLikeNodeIds = [];

        foreach ($nodes as $id => $node) {
            if (!is_array($node)) {
                continue;
            }
            $label = strtolower(trim((string) ($node['label'] ?? $id)));
            $shape = strtolower(trim((string) ($node['shape'] ?? '')));
            $text = strtolower($id . ' ' . $label);

            if ($shape === 'diamond' || str_contains($text, 'validate') || str_contains($text, 'verification') || str_contains($text, 'quality gate')) {
                $validationNodeIds[$id] = true;
            }
            if ($this->isReworkLikeText($text)) {
                $reworkNodeIds[$id] = true;
            }
            if ($this->isDraftLikeText($text)) {
                $draftLikeNodeIds[$id] = true;
            }
        }

        if (count($validationNodeIds) < 2) {
            $diagnostics[] = 'Include at least two validation nodes (initial + follow-up validation).';
        }

        $failEdges = [];
        foreach ($validationNodeIds as $validationNodeId => $_trueValue) {
            foreach ((array) ($outgoing[$validationNodeId] ?? []) as $edge) {
                if (!is_array($edge)) {
                    continue;
                }
                $label = strtolower(trim((string) ($edge['label'] ?? '')));
                if ($label === '' || (!str_contains($label, 'fail') && !str_contains($label, 'retry') && !str_contains($label, 'rework'))) {
                    continue;
                }
                $failEdges[] = [
                    'from' => $validationNodeId,
                    'to' => (string) ($edge['to'] ?? ''),
                    'label' => $label,
                ];
            }
        }

        if ($failEdges === []) {
            $diagnostics[] = 'Add at least one FAIL branch from a validation node into a rework loop.';
            return $diagnostics;
        }

        foreach ($failEdges as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            if ($to === '') {
                $diagnostics[] = "FAIL branch from {$from} is missing a target node.";
                continue;
            }
            $targetNode = $nodes[$to] ?? [];
            $targetLabel = is_array($targetNode) ? (string) ($targetNode['label'] ?? $to) : $to;
            $targetText = strtolower($to . ' ' . $targetLabel);
            if (isset($validationNodeIds[$to])) {
                $diagnostics[] = "FAIL branch from {$from} jumps directly to validation node {$to}; route it to rework first.";
                continue;
            }
            if (!isset($reworkNodeIds[$to]) && !$this->isEscalationLikeText($targetText)) {
                $diagnostics[] = "FAIL branch target {$to} should be a planning/rework/implementation node.";
            }

            $pathToValidation = $this->findPathToNextValidation($to, $from, $outgoing, $validationNodeIds, 4);
            if ($pathToValidation === null) {
                if ($this->isEscalationLikeText($targetText)) {
                    continue;
                }
                $diagnostics[] = "FAIL branch target {$to} must flow into a follow-up validation node.";
                continue;
            }

            if ($draftLikeNodeIds !== [] && !$this->pathContainsDraftOrRework($pathToValidation, $nodes)) {
                $diagnostics[] = "Retry path from {$to} should pass through a revised draft/implementation stage before validation.";
            }
        }

        return array_values(array_unique($diagnostics));
    }

    /** @param array<string,list<array<string,mixed>>> $outgoing
      * @param array<string,bool> $validationNodeIds
      * @return list<string>|null
      */
    private function findPathToNextValidation(
        string $startNodeId,
        string $sourceValidationId,
        array $outgoing,
        array $validationNodeIds,
        int $maxDepth
    ): ?array {
        $queue = [[
            'node' => $startNodeId,
            'path' => [$startNodeId],
            'depth' => 0,
        ]];
        $visited = [$startNodeId => true];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (!is_array($current)) {
                continue;
            }

            $nodeId = (string) ($current['node'] ?? '');
            $depth = (int) ($current['depth'] ?? 0);
            $path = is_array($current['path'] ?? null) ? $current['path'] : [$nodeId];

            if ($depth > 0 && isset($validationNodeIds[$nodeId]) && $nodeId !== $sourceValidationId) {
                return array_values(array_filter(array_map(static fn(mixed $id): string => (string) $id, $path), static fn(string $id): bool => $id !== ''));
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ((array) ($outgoing[$nodeId] ?? []) as $edge) {
                if (!is_array($edge)) {
                    continue;
                }
                $next = (string) ($edge['to'] ?? '');
                if ($next === '' || isset($visited[$next])) {
                    continue;
                }
                $visited[$next] = true;
                $nextPath = $path;
                $nextPath[] = $next;
                $queue[] = [
                    'node' => $next,
                    'path' => $nextPath,
                    'depth' => $depth + 1,
                ];
            }
        }

        return null;
    }

    /** @param list<string> $path
      * @param array<string,array<string,mixed>> $nodes
      */
    private function pathContainsDraftOrRework(array $path, array $nodes): bool
    {
        foreach ($path as $nodeId) {
            $node = $nodes[$nodeId] ?? [];
            if (!is_array($node)) {
                continue;
            }
            $label = strtolower(trim((string) ($node['label'] ?? $nodeId)));
            $text = strtolower($nodeId . ' ' . $label);
            if ($this->isDraftLikeText($text) || $this->isReworkLikeText($text)) {
                return true;
            }
        }
        return false;
    }

    private function isDraftLikeText(string $text): bool
    {
        return str_contains($text, 'draft')
            || str_contains($text, 'implement')
            || str_contains($text, 'plan')
            || str_contains($text, 'build')
            || str_contains($text, 'design')
            || str_contains($text, 'create');
    }

    private function isReworkLikeText(string $text): bool
    {
        return str_contains($text, 'rework')
            || str_contains($text, 'fix')
            || str_contains($text, 'retry')
            || str_contains($text, 'revise')
            || str_contains($text, 'adjust')
            || str_contains($text, 'improve')
            || str_contains($text, 'repair')
            || $this->isDraftLikeText($text);
    }

    private function isEscalationLikeText(string $text): bool
    {
        return str_contains($text, 'escalate')
            || str_contains($text, 'review')
            || str_contains($text, 'manual')
            || str_contains($text, 'human')
            || str_contains($text, 'approve')
            || str_contains($text, 'decision');
    }

    private function generationExamplesPrompt(): string
    {
        if ($this->cachedGenerationExamples !== null) {
            return $this->cachedGenerationExamples;
        }

        $root = dirname(__DIR__, 2);
        $dir = $root . '/prompts/dot-examples';
        $files = [
            'consensus_task_parity.dot',
            'megaplan_quality.dot',
            'semport.dot',
            'vulnerability_analyzer.dot',
            'consensus_task.dot',
            'megaplan.dot',
            'sprint_exec.dot',
        ];

        $sections = [];
        foreach ($files as $fileName) {
            $path = $dir . '/' . $fileName;
            if (!is_file($path)) {
                continue;
            }
            $content = trim((string) file_get_contents($path));
            if ($content === '') {
                continue;
            }
            $sections[] = "Example: {$fileName}\n```dot\n{$content}\n```";
        }

        $this->cachedGenerationExamples = implode("\n\n", $sections);
        return $this->cachedGenerationExamples;
    }

    private function normalizeCandidate(string $completion, string $prompt): string
    {
        $normalized = $this->dotService->stripMarkdownFences(trim($completion));
        if ($normalized === '') {
            return $this->dotService->fallbackFromPrompt($prompt);
        }

        $hasSvg = stripos($normalized, '<svg') !== false || stripos($normalized, '<?xml') !== false;
        if ($hasSvg) {
            return $this->dotService->fallbackFromPrompt($prompt);
        }

        if (!preg_match('/^\s*digraph\b/i', $normalized)) {
            $extracted = $this->dotService->extractFirstDigraph($normalized);
            if ($extracted !== null) {
                return $extracted;
            }

            return $this->dotService->fallbackFromPrompt($prompt);
        }

        return $normalized;
    }

    /** @param array<string,mixed> $options */
    private function complete(string $systemPrompt, string $userPrompt, array $options): string
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? (getenv('ATTRACTOR_DOT_PROVIDER') ?: 'openai'))));
        if ($provider === '') {
            $provider = 'openai';
        }

        if ($provider === 'openai') {
            return $this->completeOpenAi($systemPrompt, $userPrompt, $options);
        }
        if ($provider === 'anthropic') {
            return $this->completeAnthropic($systemPrompt, $userPrompt, $options);
        }

        throw new ApiError(400, 'BAD_REQUEST', 'provider must be one of: openai, anthropic');
    }

    /** @param array<string,mixed> $options */
    private function completeOpenAi(string $systemPrompt, string $userPrompt, array $options): string
    {
        $apiKey = trim((string) (getenv('OPENAI_API_KEY') ?: ''));
        if ($apiKey === '') {
            throw new ApiError(500, 'CONFIG_ERROR', 'OPENAI_API_KEY is required for provider=openai');
        }

        $model = trim((string) ($options['model'] ?? ''));
        if ($model === '') {
            $model = trim((string) (getenv('ATTRACTOR_OPENAI_MODEL') ?: getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini'));
        }

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [['type' => 'input_text', 'text' => $systemPrompt]],
                ],
                [
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $userPrompt]],
                ],
            ],
        ];

        $maxTokens = (int) ($options['maxTokens'] ?? 0);
        if ($maxTokens > 0) {
            $payload['max_output_tokens'] = $maxTokens;
        }

        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $baseUrl = rtrim((string) (getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1'), '/');
        $response = $this->postJson(
            $baseUrl . '/responses',
            [
                'authorization: Bearer ' . $apiKey,
                'content-type: application/json',
            ],
            $payload,
            'openai'
        );

        $outputText = trim((string) ($response['output_text'] ?? ''));
        if ($outputText !== '') {
            return $outputText;
        }

        $parts = [];
        foreach ((array) ($response['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (!is_array($content)) {
                    continue;
                }
                $text = trim((string) ($content['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        $joined = trim(implode("\n", $parts));
        if ($joined !== '') {
            return $joined;
        }

        throw new ApiError(502, 'UPSTREAM_ERROR', 'openai response did not include text output');
    }

    /** @param array<string,mixed> $options */
    private function completeAnthropic(string $systemPrompt, string $userPrompt, array $options): string
    {
        $apiKey = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));
        if ($apiKey === '') {
            throw new ApiError(500, 'CONFIG_ERROR', 'ANTHROPIC_API_KEY is required for provider=anthropic');
        }

        $model = trim((string) ($options['model'] ?? ''));
        if ($model === '') {
            $model = trim((string) (getenv('ATTRACTOR_ANTHROPIC_MODEL') ?: getenv('ANTHROPIC_MODEL') ?: ''));
        }
        if ($model === '') {
            throw new ApiError(500, 'CONFIG_ERROR', 'Anthropic model is required; set ATTRACTOR_ANTHROPIC_MODEL, ANTHROPIC_MODEL, or request model');
        }

        $payload = [
            'model' => $model,
            'system' => $systemPrompt,
            'max_tokens' => max(256, (int) ($options['maxTokens'] ?? 1200)),
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $baseUrl = rtrim((string) (getenv('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com/v1'), '/');
        $response = $this->postJson(
            $baseUrl . '/messages',
            [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            $payload,
            'anthropic'
        );

        $parts = [];
        foreach ((array) ($response['content'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['type'] ?? '') !== 'text') {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $joined = trim(implode("\n", $parts));
        if ($joined !== '') {
            return $joined;
        }

        throw new ApiError(502, 'UPSTREAM_ERROR', 'anthropic response did not include text output');
    }

    /**
     * @param list<string> $headers
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postJson(string $url, array $headers, array $payload, string $provider): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to encode provider payload');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to initialize curl');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            $error = curl_error($ch);
            throw new ApiError(502, 'UPSTREAM_ERROR', "{$provider} request failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiError(502, 'UPSTREAM_ERROR', "{$provider} returned non-JSON response");
        }

        if ($status < 200 || $status >= 300) {
            $message = trim((string) ($decoded['error']['message'] ?? $decoded['error'] ?? 'request failed'));
            if ($message === '') {
                $message = 'request failed';
            }
            throw new ApiError(502, 'UPSTREAM_ERROR', "{$provider} API error ({$status}): {$message}");
        }

        return $decoded;
    }
}
