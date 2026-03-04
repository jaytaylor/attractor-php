<?php

declare(strict_types=1);

namespace App\Services;

final class DotService
{
    private const PROVIDER_OPENAI = 'openai';
    private const PROVIDER_ANTHROPIC = 'anthropic';
    private const PROVIDER_GEMINI = 'gemini';
    private const MODEL_CATALOG_TTL_SECONDS = 300;
    private const CUSTOM_MODEL_SENTINEL = '__custom__';
    private const DOT_REFERENCE_EXAMPLE_FILES = [
        'consensus_task_parity.dot',
        'megaplan_quality.dot',
        'semport.dot',
        'vulnerability_analyzer.dot',
        'consensus_task.dot',
        'megaplan.dot',
        'sprint_exec.dot',
    ];

    /**
     * @var array<string,list<string>>
     */
    private const FALLBACK_MODELS = [
        self::PROVIDER_OPENAI => [
            'gpt-5-chat-latest',
            'gpt-5',
            'gpt-5-mini',
            'gpt-5-nano',
            'gpt-5.2-chat-latest',
            'gpt-5.1-chat-latest',
            'gpt-4.1',
            'gpt-4o',
            'o3',
            'o4-mini',
        ],
        self::PROVIDER_ANTHROPIC => [
            'claude-sonnet-4-6',
            'claude-opus-4-6',
            'claude-sonnet-4-5-20250929',
            'claude-haiku-4-5-20251001',
        ],
        self::PROVIDER_GEMINI => [
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-2.5-pro',
            'gemini-3-flash-preview',
            'gemini-3.1-pro-preview',
            'gemini-3.1-flash-lite-preview',
            'gemini-flash-latest',
            'gemini-pro-latest',
        ],
    ];

    /**
     * @var array<string,array{cachedAt:int,payload:array{provider:string,defaultModel:string,models:list<string>,source:string,warnings:list<string>}>>
     */
    private static array $modelCatalogCache = [];
    private static ?string $dotSystemPromptCache = null;

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
     * @return array{provider:string,defaultModel:string,models:list<string>,source:string,warnings:list<string>}
     */
    public function listProviderModels(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $validProviders = [self::PROVIDER_OPENAI, self::PROVIDER_ANTHROPIC, self::PROVIDER_GEMINI];
        if (!in_array($provider, $validProviders, true)) {
            throw new DotServiceException('provider must be one of: openai, anthropic, gemini', 'BAD_REQUEST', 400);
        }

        $cached = self::$modelCatalogCache[$provider] ?? null;
        if (is_array($cached) && ((time() - (int) ($cached['cachedAt'] ?? 0)) < self::MODEL_CATALOG_TTL_SECONDS)) {
            return $cached['payload'];
        }

        $warnings = [];
        $models = $this->fallbackModelsForProvider($provider);
        $source = 'fallback';

        try {
            $live = match ($provider) {
                self::PROVIDER_OPENAI => $this->fetchOpenAIModels(),
                self::PROVIDER_ANTHROPIC => $this->fetchAnthropicModels(),
                self::PROVIDER_GEMINI => $this->fetchGeminiModels(),
                default => [],
            };
            if ($live !== []) {
                $models = $live;
                $source = 'live';
            } else {
                $warnings[] = 'live model catalog returned empty; using fallback list';
            }
        } catch (DotServiceException $e) {
            $warnings[] = $e->getMessage();
        }

        $models = $this->sortedModels($provider, $models);
        if ($models === []) {
            throw new DotServiceException('no models available for provider', 'PROVIDER_MODELS_UNAVAILABLE', 502);
        }

        $default = $this->defaultModelForProvider($provider);
        if ($default === '' || !in_array($default, $models, true)) {
            $default = $this->preferredDefaultForProvider($provider, $models);
        }

        $payload = [
            'provider' => $provider,
            'defaultModel' => $default,
            'models' => $models,
            'source' => $source,
            'warnings' => $warnings,
        ];
        self::$modelCatalogCache[$provider] = [
            'cachedAt' => time(),
            'payload' => $payload,
        ];

        return $payload;
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

        $operationPrompt = "Task: Generate a Graphviz DOT directed graph for this goal:\n{$prompt}\n\n"
            . "Attractor critical requirement:\n"
            . "- Include explicit validation/checkpoint stages that verify output quality and requirement compliance.\n"
            . "- Include at least one failure branch from a validation stage back to planning or implementation rework.\n"
            . "- Include a success branch from validation toward completion.";
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
     * Execute a general-purpose text completion against a provider/model.
     *
     * @param array{provider?:string,model?:string} $options
     * @return array{provider:string,model:string,text:string}
     */
    public function completeText(string $systemPrompt, string $userPrompt, array $options = []): array
    {
        [$provider, $model] = $this->resolveProviderAndModel($options);
        $text = trim($this->callProvider($provider, $model, $systemPrompt, $userPrompt));
        if ($text === '') {
            throw new DotServiceException('provider returned empty text output', 'UPSTREAM_INVALID_RESPONSE', 502);
        }
        return [
            'provider' => $provider,
            'model' => $model,
            'text' => $text,
        ];
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
        $semanticDiagnostics = $operation === 'generate' ? $this->validateAttractorValidationSemantics($dot) : [];
        if ($validation['valid'] && $semanticDiagnostics === []) {
            return $dot;
        }
        if ($operation === 'generate' && $validation['valid'] && $semanticDiagnostics !== []) {
            $enforced = $this->enforceGenerateValidationLoop($dot);
            $enforcedValidation = $this->validate($enforced);
            $enforcedSemanticDiagnostics = $this->validateAttractorValidationSemantics($enforced);
            if ($enforcedValidation['valid'] && $enforcedSemanticDiagnostics === []) {
                return $enforced;
            }
        }
        $allDiagnostics = array_merge($validation['diagnostics'], $semanticDiagnostics);

        $repairPrompt = "The previous response for {$operation} failed DOT constraints.\n"
            . 'Validation diagnostics: ' . $this->diagnosticsSummary($allDiagnostics) . "\n"
            . "Previous response:\n{$raw}\n"
            . "Return only corrected DOT.";

        $retryRaw = $this->callProvider($provider, $model, $systemPrompt, $repairPrompt);
        $retryDot = $this->extractDotGraph($retryRaw);
        $retryValidation = $this->validate($retryDot);
        $retrySemanticDiagnostics = $operation === 'generate' ? $this->validateAttractorValidationSemantics($retryDot) : [];
        if ($retryValidation['valid'] && $retrySemanticDiagnostics === []) {
            return $retryDot;
        }
        if ($operation === 'generate' && $retryValidation['valid'] && $retrySemanticDiagnostics !== []) {
            $enforcedRetry = $this->enforceGenerateValidationLoop($retryDot);
            $enforcedRetryValidation = $this->validate($enforcedRetry);
            $enforcedRetrySemanticDiagnostics = $this->validateAttractorValidationSemantics($enforcedRetry);
            if ($enforcedRetryValidation['valid'] && $enforcedRetrySemanticDiagnostics === []) {
                return $enforcedRetry;
            }
        }
        $retryAllDiagnostics = array_merge($retryValidation['diagnostics'], $retrySemanticDiagnostics);

        throw new DotServiceException(
            'provider returned invalid DOT after retry: ' . $this->diagnosticsSummary($retryAllDiagnostics),
            'INVALID_DOT',
            502,
        );
    }

    private function dotSystemPrompt(): string
    {
        if (self::$dotSystemPromptCache !== null) {
            return self::$dotSystemPromptCache;
        }

        $base = "You are a Graphviz DOT generation engine for software workflow pipelines.\n"
            . "Hard requirements:\n"
            . "- Return ONLY DOT source text for a directed graph starting with digraph.\n"
            . "- Do not include markdown, code fences, or commentary.\n"
            . "- The graph must include at least one edge.\n"
            . "- Ensure balanced braces and valid node IDs with letters, numbers, and underscores.\n"
            . "- Include a terminal node named done with shape=Msquare unless an explicit terminal node is already defined.\n"
            . "- Model validation as a first-class stage in the workflow.\n"
            . "- Add explicit pass/fail branching at validation nodes.\n"
            . "- Ensure failed validation routes back to planning or implementation rework.\n\n"
            . "Validation design conventions expected in generated graphs:\n"
            . "- Planning/implementation nodes should produce candidate output.\n"
            . "- Validation/check nodes verify candidate output against requirements.\n"
            . "- Pass path advances toward completion.\n"
            . "- Fail path loops to planner/implementor/rework and then back to validation.\n";

        $examples = $this->dotReferenceExamplesSection();
        self::$dotSystemPromptCache = $examples === '' ? $base : $base . "\n" . $examples;
        return self::$dotSystemPromptCache;
    }

    /**
     * @return list<array{severity:string,message:string}>
     */
    private function validateAttractorValidationSemantics(string $dot): array
    {
        $diagnostics = [];
        $nodeLabels = [];
        if (preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*\[(.*?)\]\s*;?\s*$/m', $dot, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $nodeId = strtolower((string) ($match[1] ?? ''));
                $attrs = (string) ($match[2] ?? '');
                $label = '';
                if (preg_match('/\blabel\s*=\s*"([^"]+)"/i', $attrs, $labelMatch) === 1) {
                    $label = strtolower(trim((string) ($labelMatch[1] ?? '')));
                }
                $nodeLabels[$nodeId] = $label;
            }
        }

        $edges = [];
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*->\s*([A-Za-z_][A-Za-z0-9_]*)/', $dot, $edgeMatches, PREG_SET_ORDER)) {
            foreach ($edgeMatches as $edge) {
                $from = strtolower((string) ($edge[1] ?? ''));
                $to = strtolower((string) ($edge[2] ?? ''));
                if ($from === '' || $to === '') {
                    continue;
                }
                $edges[] = ['from' => $from, 'to' => $to];
            }
        }

        $validationKeywords = ['validate', 'validation', 'verify', 'verification', 'review', 'qa', 'check', 'test', 'audit'];
        $reworkKeywords = ['plan', 'planning', 'implement', 'implementation', 'build', 'code', 'develop', 'create', 'draft', 'design', 'rework', 'fix', 'repair', 'iterate'];
        $successKeywords = ['done', 'complete', 'completed', 'deliver', 'publish', 'release', 'ship', 'deploy', 'approve', 'final'];

        $validationNodes = [];
        foreach ($edges as $edge) {
            foreach (['from', 'to'] as $side) {
                $nodeId = $edge[$side];
                if ($this->containsKeyword($nodeId, $validationKeywords) || $this->containsKeyword($nodeLabels[$nodeId] ?? '', $validationKeywords)) {
                    $validationNodes[$nodeId] = true;
                }
            }
        }
        foreach (array_keys($nodeLabels) as $nodeId) {
            if ($this->containsKeyword($nodeId, $validationKeywords) || $this->containsKeyword($nodeLabels[$nodeId] ?? '', $validationKeywords)) {
                $validationNodes[$nodeId] = true;
            }
        }

        if ($validationNodes === []) {
            $diagnostics[] = [
                'severity' => 'error',
                'message' => 'generate output must include explicit validation/checkpoint/test/review stage nodes',
            ];
            return $diagnostics;
        }

        $hasReworkKickback = false;
        $hasSuccessPath = false;
        foreach ($edges as $edge) {
            if (!isset($validationNodes[$edge['from']])) {
                continue;
            }
            $to = $edge['to'];
            $toLabel = $nodeLabels[$to] ?? '';
            if ($this->containsKeyword($to, $reworkKeywords) || $this->containsKeyword($toLabel, $reworkKeywords)) {
                $hasReworkKickback = true;
            }
            if ($this->containsKeyword($to, $successKeywords) || $this->containsKeyword($toLabel, $successKeywords)) {
                $hasSuccessPath = true;
            }
        }

        if (!$hasReworkKickback) {
            $diagnostics[] = [
                'severity' => 'error',
                'message' => 'validation stage must include a failure kickback path to planning or implementation rework',
            ];
        }
        if (!$hasSuccessPath) {
            $diagnostics[] = [
                'severity' => 'error',
                'message' => 'validation stage must include a success path toward completion/delivery',
            ];
        }

        return $diagnostics;
    }

    private function containsKeyword(string $value, array $keywords): bool
    {
        $needle = strtolower($value);
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($needle, $keyword)) {
                return true;
            }
        }
        return false;
    }

    private function enforceGenerateValidationLoop(string $dot): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $dot);
        $closePos = strrpos($normalized, '}');
        if ($closePos === false) {
            return $dot;
        }

        $prefix = rtrim(substr($normalized, 0, $closePos));
        $suffix = substr($normalized, $closePos);

        $existing = [];
        if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\b/', $prefix, $tokenMatches)) {
            foreach ($tokenMatches[1] as $token) {
                $existing[strtolower((string) $token)] = true;
            }
        }

        if (!preg_match('/\bdone\b/i', $prefix)) {
            $prefix .= "\n  done [shape=Msquare];";
            $existing['done'] = true;
        }

        $validationNode = $this->uniqueNodeId('validation_gate', $existing);
        $plannerNode = $this->uniqueNodeId('planning_rework', $existing);
        $implementNode = $this->uniqueNodeId('implement_rework', $existing);

        $predecessors = [];
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*->\s*done\b/i', $prefix, $predMatches, PREG_SET_ORDER)) {
            foreach ($predMatches as $match) {
                $from = strtolower((string) ($match[1] ?? ''));
                if ($from !== '' && $from !== 'done') {
                    $predecessors[$from] = true;
                }
            }
        }

        if ($predecessors === [] && preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*->\s*([A-Za-z_][A-Za-z0-9_]*)/i', $prefix, $edgeMatches, PREG_SET_ORDER)) {
            $last = end($edgeMatches);
            if (is_array($last)) {
                $candidate = strtolower((string) ($last[2] ?? ''));
                if ($candidate !== '' && $candidate !== 'done') {
                    $predecessors[$candidate] = true;
                }
            }
        }

        if ($predecessors === []) {
            $predecessors['start'] = true;
        }

        $appendLines = [];
        $appendLines[] = "{$validationNode} [label=\"Validate requirements and quality\"];";
        $appendLines[] = "{$plannerNode} [label=\"Plan rework\"];";
        $appendLines[] = "{$implementNode} [label=\"Implement rework\"];";
        foreach (array_keys($predecessors) as $fromNode) {
            $appendLines[] = "{$fromNode} -> {$validationNode};";
        }
        $appendLines[] = "{$validationNode} -> done [label=\"pass\"];";
        $appendLines[] = "{$validationNode} -> {$plannerNode} [label=\"fail\"];";
        $appendLines[] = "{$plannerNode} -> {$implementNode};";
        $appendLines[] = "{$implementNode} -> {$validationNode};";

        return $prefix . "\n\n  " . implode("\n  ", $appendLines) . "\n" . $suffix;
    }

    /**
     * @param array<string,bool> $existing
     */
    private function uniqueNodeId(string $baseId, array &$existing): string
    {
        $candidate = strtolower($baseId);
        if (!isset($existing[$candidate])) {
            $existing[$candidate] = true;
            return $candidate;
        }

        $counter = 2;
        while (isset($existing[$candidate . '_' . $counter])) {
            $counter++;
        }
        $candidate = $candidate . '_' . $counter;
        $existing[$candidate] = true;
        return $candidate;
    }

    private function dotReferenceExamplesSection(): string
    {
        $projectRoot = dirname(__DIR__, 2);
        $examplesDir = $projectRoot . '/resources/prompts/dot/examples';
        $sections = [];

        foreach (self::DOT_REFERENCE_EXAMPLE_FILES as $fileName) {
            $path = $examplesDir . '/' . $fileName;
            if (!is_file($path)) {
                continue;
            }
            $content = file_get_contents($path);
            if (!is_string($content)) {
                continue;
            }
            $trimmed = trim(str_replace(["\r\n", "\r"], "\n", $content));
            if ($trimmed === '') {
                continue;
            }
            $sections[] = "Example: {$fileName}\n```dot\n{$trimmed}\n```";
        }

        if ($sections === []) {
            return '';
        }

        return "Reference quality DOT examples (authoritative style guides):\n"
            . "- Use these to match graph structure quality, validation rigor, and rework-loop modeling.\n"
            . implode("\n\n", $sections);
    }

    /**
     * @param array{provider?:string,model?:string} $options
     * @return array{0:string,1:string}
     */
    private function resolveProviderAndModel(array $options): array
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? (getenv('DOT_LLM_PROVIDER') ?: ''))));
        if ($provider === '') {
            $provider = $this->firstConfiguredProvider();
        }
        if ($provider === '') {
            $provider = self::PROVIDER_OPENAI;
        }
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

    private function firstConfiguredProvider(): string
    {
        if (trim((string) getenv('OPENAI_API_KEY')) !== '') {
            return self::PROVIDER_OPENAI;
        }
        if (trim((string) getenv('ANTHROPIC_API_KEY')) !== '') {
            return self::PROVIDER_ANTHROPIC;
        }
        if (trim((string) getenv('GEMINI_API_KEY')) !== '' || trim((string) getenv('GOOGLE_API_KEY')) !== '') {
            return self::PROVIDER_GEMINI;
        }
        return '';
    }

    private function defaultModelForProvider(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_OPENAI => (string) (getenv('DOT_OPENAI_MODEL') ?: 'gpt-5-chat-latest'),
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

    /**
     * @return list<string>
     */
    private function fallbackModelsForProvider(string $provider): array
    {
        return self::FALLBACK_MODELS[$provider] ?? [];
    }

    /**
     * @param list<string> $models
     * @return list<string>
     */
    private function sortedModels(string $provider, array $models): array
    {
        $clean = [];
        foreach ($models as $model) {
            $value = trim((string) $model);
            if ($value === '' || $value === self::CUSTOM_MODEL_SENTINEL) {
                continue;
            }
            $clean[$value] = true;
        }
        $list = array_keys($clean);

        $priority = $this->priorityOrderForProvider($provider);
        $rank = [];
        foreach ($priority as $i => $modelId) {
            $rank[$modelId] = $i;
        }

        usort($list, static function (string $a, string $b) use ($rank): int {
            $aRank = $rank[$a] ?? 10_000;
            $bRank = $rank[$b] ?? 10_000;
            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }
            return strnatcasecmp($a, $b);
        });

        return array_values($list);
    }

    /**
     * @return list<string>
     */
    private function priorityOrderForProvider(string $provider): array
    {
        return match ($provider) {
            self::PROVIDER_OPENAI => [
                'gpt-5-chat-latest',
                'gpt-5',
                'gpt-5-mini',
                'gpt-5-nano',
                'gpt-5.2-chat-latest',
                'gpt-5.2',
                'gpt-5.1-chat-latest',
                'gpt-5.1',
                'gpt-4.1',
                'gpt-4.1-mini',
                'gpt-4o',
                'o3',
                'o4-mini',
            ],
            self::PROVIDER_ANTHROPIC => [
                'claude-sonnet-4-6',
                'claude-opus-4-6',
                'claude-sonnet-4-5-20250929',
                'claude-opus-4-5-20251101',
                'claude-haiku-4-5-20251001',
                'claude-3-haiku-20240307',
            ],
            self::PROVIDER_GEMINI => [
                'gemini-2.5-flash',
                'gemini-2.5-pro',
                'gemini-2.5-flash-lite',
                'gemini-3-flash-preview',
                'gemini-3.1-pro-preview',
                'gemini-3.1-flash-lite-preview',
                'gemini-flash-latest',
                'gemini-flash-lite-latest',
                'gemini-pro-latest',
            ],
            default => [],
        };
    }

    /**
     * @param list<string> $models
     */
    private function preferredDefaultForProvider(string $provider, array $models): string
    {
        foreach ($this->priorityOrderForProvider($provider) as $candidate) {
            if (in_array($candidate, $models, true)) {
                return $candidate;
            }
        }
        return $models[0] ?? '';
    }

    /**
     * @return list<string>
     */
    private function fetchOpenAIModels(): array
    {
        $apiKey = trim((string) getenv('OPENAI_API_KEY'));
        if ($apiKey === '') {
            throw new DotServiceException('OPENAI_API_KEY is not configured', 'PROVIDER_NOT_CONFIGURED', 500);
        }

        $base = trim((string) getenv('OPENAI_BASE_URL'));
        if ($base === '') {
            $base = 'https://api.openai.com';
        }

        $url = $this->joinApiPath($base, '/v1/models');
        $response = $this->getJson(
            $url,
            ['Authorization: Bearer ' . $apiKey],
            'openai',
        );

        $models = [];
        $items = $response['data'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '' || !$this->isOpenAITextModel($id)) {
                    continue;
                }
                $models[] = $id;
            }
        }

        return $models;
    }

    /**
     * @return list<string>
     */
    private function fetchAnthropicModels(): array
    {
        $apiKey = trim((string) getenv('ANTHROPIC_API_KEY'));
        if ($apiKey === '') {
            throw new DotServiceException('ANTHROPIC_API_KEY is not configured', 'PROVIDER_NOT_CONFIGURED', 500);
        }

        $base = trim((string) getenv('ANTHROPIC_BASE_URL'));
        if ($base === '') {
            $base = 'https://api.anthropic.com';
        }

        $url = $this->joinApiPath($base, '/v1/models');
        $response = $this->getJson(
            $url,
            [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            'anthropic',
        );

        $models = [];
        $items = $response['data'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '' || !str_starts_with($id, 'claude-')) {
                    continue;
                }
                $models[] = $id;
            }
        }

        return $models;
    }

    /**
     * @return list<string>
     */
    private function fetchGeminiModels(): array
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

        $url = rtrim($base, '/') . '/v1beta/models?key=' . rawurlencode($apiKey);
        $response = $this->getJson($url, [], 'gemini');

        $models = [];
        $items = $response['models'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if (str_starts_with($name, 'models/')) {
                    $name = substr($name, strlen('models/'));
                }
                if (!preg_match('/^(gemini|gemma)-/i', $name)) {
                    continue;
                }
                $methods = $item['supportedGenerationMethods'] ?? [];
                if (!is_array($methods) || !in_array('generateContent', $methods, true)) {
                    continue;
                }
                if (
                    str_contains($name, 'image')
                    || str_contains($name, 'audio')
                    || str_contains($name, 'tts')
                    || str_contains($name, 'embedding')
                    || str_contains($name, 'computer-use')
                    || str_contains($name, 'robotics')
                ) {
                    continue;
                }
                $models[] = $name;
            }
        }

        return $models;
    }

    private function isOpenAITextModel(string $modelId): bool
    {
        if (!preg_match('/^(gpt|o[0-9]|chatgpt)/', $modelId)) {
            return false;
        }

        $blockedTokens = [
            'embedding',
            'moderation',
            'realtime',
            'audio',
            'transcribe',
            'tts',
            'image',
            'search-api',
            'search-preview',
            'instruct',
        ];
        foreach ($blockedTokens as $token) {
            if (str_contains($modelId, $token)) {
                return false;
            }
        }

        return true;
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
     * @return array<string,mixed>
     */
    private function getJson(string $url, array $headers, string $provider): array
    {
        if (!function_exists('curl_init')) {
            throw new DotServiceException('curl extension is required for LLM provider calls', 'CONFIG_ERROR', 500);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new DotServiceException('failed to initialize provider request', 'INTERNAL_ERROR', 500);
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        // Provider inference can exceed PHP's default max_execution_time for complex prompts.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
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

        // Provider inference can exceed PHP's default max_execution_time for complex prompts.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
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
