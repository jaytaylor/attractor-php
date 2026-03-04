<?php

declare(strict_types=1);

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

function assertTrue(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "ASSERT FAILED: {$message}\n");
        exit(1);
    }
}

/**
 * @return array{status:int,body:string,json:array<string,mixed>|null,headers:list<string>}
 */
function request(string $method, string $url, ?array $json = null): array
{
    $headers = [
        'Content-Type: application/json',
    ];

    $content = '';
    if ($json !== null) {
        $content = (string) json_encode($json);
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if (!is_string($body)) {
        $body = '';
    }

    $rawHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($rawHeaders as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
            break;
        }
    }

    $decoded = json_decode($body, true);
    return [
        'status' => $status,
        'body' => $body,
        'json' => is_array($decoded) ? $decoded : null,
        'headers' => $rawHeaders,
    ];
}

/**
 * @return array{provider:string,model:string}
 */
function providerForDotTests(): array
{
    $explicitProvider = strtolower(trim((string) getenv('DOT_TEST_PROVIDER')));
    if ($explicitProvider !== '') {
        $explicitModel = trim((string) getenv('DOT_TEST_MODEL'));
        return ['provider' => $explicitProvider, 'model' => $explicitModel];
    }

    if (trim((string) getenv('OPENAI_API_KEY')) !== '') {
        return ['provider' => 'openai', 'model' => trim((string) getenv('DOT_OPENAI_MODEL'))];
    }
    if (trim((string) getenv('ANTHROPIC_API_KEY')) !== '') {
        return ['provider' => 'anthropic', 'model' => trim((string) getenv('DOT_ANTHROPIC_MODEL'))];
    }
    if (trim((string) getenv('GEMINI_API_KEY')) !== '' || trim((string) getenv('GOOGLE_API_KEY')) !== '') {
        return ['provider' => 'gemini', 'model' => trim((string) getenv('DOT_GEMINI_MODEL'))];
    }

    fwrite(STDERR, "No provider API key configured for DOT endpoint tests\n");
    exit(1);
}

function appendProviderPayload(array $payload, array $providerConfig): array
{
    $payload['provider'] = $providerConfig['provider'];
    if (($providerConfig['model'] ?? '') !== '') {
        $payload['model'] = $providerConfig['model'];
    }
    return $payload;
}

function extractDotFromSse(string $body): string
{
    $dot = '';
    foreach (explode("\n", $body) as $line) {
        if (!str_starts_with($line, 'data: ')) {
            continue;
        }
        $frame = json_decode(substr($line, 6), true);
        if (!is_array($frame)) {
            continue;
        }
        if (isset($frame['delta']) && is_string($frame['delta'])) {
            $dot .= $frame['delta'];
        }
        if (isset($frame['done']) && $frame['done'] === true && isset($frame['dotSource']) && is_string($frame['dotSource'])) {
            $dot = $frame['dotSource'];
        }
    }
    return trim($dot);
}

$root = dirname(__DIR__);
$runtimeRoot = $root . '/.scratch/runtime';
rrmdir($runtimeRoot);
@mkdir($root . '/.scratch/verification/SPRINT-002/final', 0777, true);

$port = 18082;
$cmd = sprintf('php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($root . '/public'), escapeshellarg($root . '/public/index.php'));
$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['file', $root . '/.scratch/verification/SPRINT-002/final/php-server.log', 'w'],
    2 => ['file', $root . '/.scratch/verification/SPRINT-002/final/php-server.log', 'a'],
];
$proc = proc_open($cmd, $descriptorspec, $pipes, $root);
assertTrue(is_resource($proc), 'server failed to start');

$base = "http://127.0.0.1:{$port}";
$up = false;
for ($i = 0; $i < 40; $i++) {
    usleep(100000);
    $res = request('GET', $base . '/');
    if ($res['status'] === 200) {
        $up = true;
        break;
    }
}
assertTrue($up, 'server did not come up');

$res = request('GET', $base . '/');
assertTrue($res['status'] === 200, 'GET / should return 200');
assertTrue(str_contains($res['body'], 'Attractor PHP Dashboard'), '/ should contain dashboard text');

$res = request('POST', $base . '/api/v1/dot/validate', ['dotSource' => 'digraph A { a -> b; }']);
assertTrue($res['status'] === 200, 'validate should return 200');
assertTrue(($res['json']['valid'] ?? false) === true, 'validate should be valid for correct dot');

$res = request('POST', $base . '/api/v1/dot/render', ['dotSource' => 'digraph A { a -> b; }']);
assertTrue($res['status'] === 200, 'render should return 200 for valid dot');
$svg = (string) ($res['json']['svg'] ?? '');
assertTrue(str_contains($svg, '<svg'), 'rendered svg should include <svg root');
assertTrue(str_contains($svg, 'graph0'), 'rendered svg should include graphviz graph group');
assertTrue(!str_contains($svg, 'DOT Preview'), 'render should not use text-only placeholder svg');

$res = request('POST', $base . '/api/v1/pipelines', ['dotSource' => 'bad']);
assertTrue($res['status'] === 400, 'invalid create should fail');

$providerConfig = providerForDotTests();
$defaultProviderModels = [
    'openai' => trim((string) getenv('DOT_OPENAI_MODEL')),
    'anthropic' => trim((string) getenv('DOT_ANTHROPIC_MODEL')),
    'gemini' => trim((string) getenv('DOT_GEMINI_MODEL')),
];

foreach (['openai', 'anthropic', 'gemini'] as $providerId) {
    $res = request('GET', $base . '/api/v1/dot/models?provider=' . rawurlencode($providerId));
    assertTrue($res['status'] === 200, 'dot model catalog should return 200 for ' . $providerId);
    $models = $res['json']['models'] ?? [];
    assertTrue(is_array($models) && count($models) > 0, 'dot model catalog should list models for ' . $providerId);
    $defaultModel = (string) ($res['json']['defaultModel'] ?? '');
    assertTrue($defaultModel !== '', 'dot model catalog should include default model for ' . $providerId);
    assertTrue(in_array($defaultModel, $models, true), 'default model should exist in model list for ' . $providerId);

    $expected = (string) ($defaultProviderModels[$providerId] ?? '');
    if ($expected !== '') {
        assertTrue(in_array($expected, $models, true), 'configured/default model should exist in model list for ' . $providerId);
    }
}

$dot = "digraph Pipeline {\n  start -> review;\n  review -> done;\n  done [shape=Msquare];\n}";
$res = request('POST', $base . '/api/v1/pipelines', [
    'dotSource' => $dot,
    'displayName' => 'AutoApprove Run',
    'simulate' => true,
    'autoApprove' => true,
    'originalPrompt' => 'test auto approve',
]);
assertTrue($res['status'] === 201, 'create run should return 201');
$runId = (string) ($res['json']['id'] ?? '');
assertTrue($runId !== '', 'run id missing');

$res = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($runId));
assertTrue($res['status'] === 200, 'get run should return 200');
assertTrue(($res['json']['status'] ?? '') === 'completed', 'auto approve run should complete');

$res = request('GET', $base . '/api/v1/pipelines');
assertTrue($res['status'] === 200, 'list runs should return 200');
$items = $res['json']['items'] ?? [];
assertTrue(is_array($items) && count($items) >= 1, 'run list should include created run');

$res = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($runId) . '/events');
assertTrue($res['status'] === 200, 'run events should return 200');
assertTrue(str_contains($res['body'], 'Snapshot'), 'events should include snapshot');
assertTrue(str_contains($res['body'], 'PipelineStarted'), 'events should include pipeline started');

$res = request('GET', $base . '/api/v1/events');
assertTrue($res['status'] === 200, 'global events should return 200');
assertTrue(str_contains($res['body'], 'Snapshot'), 'global events should include snapshot');

$res = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($runId) . '/artifacts');
assertTrue($res['status'] === 200, 'list artifacts should return 200');
$artifacts = $res['json']['items'] ?? [];
assertTrue(is_array($artifacts) && count($artifacts) > 0, 'artifacts should not be empty');
$artifactPath = (string) ($artifacts[0]['path'] ?? '');
assertTrue($artifactPath !== '', 'artifact path should exist');

$res = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($runId) . '/artifacts/' . str_replace('%2F', '/', rawurlencode($artifactPath)));
assertTrue($res['status'] === 200, 'artifact file should return 200');

$res = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($runId) . '/artifacts/../README.md');
assertTrue(in_array($res['status'], [400, 404], true), 'path traversal should be rejected');

$res = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($runId) . '/checkpoint');
assertTrue($res['status'] === 200, 'checkpoint should return 200');
assertTrue(isset($res['json']['current_node']), 'checkpoint shape invalid');

$res = request('GET', $base . '/pipelines/' . rawurlencode($runId) . '/context');
assertTrue($res['status'] === 200, 'alias context should return 200');
assertTrue(isset($res['json']['run.id']), 'context shape invalid');

$res = request('POST', $base . '/api/v1/dot/generate/stream', appendProviderPayload([
    'prompt' => 'Build release pipeline with plan build test deploy nodes',
], $providerConfig));
assertTrue($res['status'] === 200, 'generate stream should return 200');
assertTrue(str_contains($res['body'], '"delta"'), 'generate stream should include delta');
assertTrue(str_contains($res['body'], '"done":true'), 'generate stream should include done frame');
$generatedDot = extractDotFromSse($res['body']);
assertTrue($generatedDot !== '', 'generate stream should include final dotSource');

$res = request('POST', $base . '/api/v1/dot/validate', ['dotSource' => $generatedDot]);
assertTrue($res['status'] === 200, 'validate generated dot should return 200');
assertTrue(($res['json']['valid'] ?? false) === true, 'generated dot should validate');

$res = request('POST', $base . '/api/v1/dot/fix/stream', appendProviderPayload([
    'dotSource' => '```dot
a->b
```',
    'error' => 'parse',
], $providerConfig));
assertTrue($res['status'] === 200, 'fix stream should return 200');
$fixedDot = extractDotFromSse($res['body']);
assertTrue($fixedDot !== '', 'fix stream should include final dotSource');
assertTrue(!str_contains($fixedDot, '```'), 'fixed dot should strip markdown fences');
$res = request('POST', $base . '/api/v1/dot/validate', ['dotSource' => $fixedDot]);
assertTrue($res['status'] === 200, 'validate fixed dot should return 200');
assertTrue(($res['json']['valid'] ?? false) === true, 'fixed dot should validate');

$res = request('POST', $base . '/api/v1/dot/iterate', appendProviderPayload([
    'baseDot' => $generatedDot,
    'changes' => 'Add approval gate and connect it before done',
], $providerConfig));
assertTrue($res['status'] === 200, 'iterate endpoint should return 200');
$newDot = (string) ($res['json']['dotSource'] ?? '');
assertTrue($newDot !== '', 'iterated dot should not be empty');
$res = request('POST', $base . '/api/v1/dot/validate', ['dotSource' => $newDot]);
assertTrue($res['status'] === 200, 'validate iterated dot should return 200');
assertTrue(($res['json']['valid'] ?? false) === true, 'iterated dot should validate');

$res = request('POST', $base . '/api/v1/pipelines/' . rawurlencode($runId) . '/iterate', [
    'dotSource' => $newDot,
    'originalPrompt' => 'iterate this run',
]);
assertTrue($res['status'] === 200, 'iterate run should return 200');
$iteratedRunId = (string) ($res['json']['newId'] ?? '');
assertTrue($iteratedRunId !== '', 'iterate run should return new id');

$src = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($runId));
$new = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($iteratedRunId));
assertTrue(($src['json']['familyId'] ?? '') === ($new['json']['familyId'] ?? ''), 'iterate run should preserve familyId');

$res = request('POST', $base . '/api/v1/pipelines/' . rawurlencode($runId) . '/archive');
assertTrue($res['status'] === 200, 'archive should return 200');
$res = request('GET', $base . '/api/v1/pipelines?archived=only');
assertTrue($res['status'] === 200, 'archived listing should return 200');
assertTrue(count($res['json']['items'] ?? []) >= 1, 'archived listing should include run');
$res = request('POST', $base . '/api/v1/pipelines/' . rawurlencode($runId) . '/unarchive');
assertTrue($res['status'] === 200, 'unarchive should return 200');

$res = request('POST', $base . '/api/v1/pipelines', [
    'dotSource' => $dot,
    'displayName' => 'Human Gate Run',
    'simulate' => true,
    'autoApprove' => false,
]);
assertTrue($res['status'] === 201, 'human gate run create should return 201');
$humanRunId = (string) ($res['json']['id'] ?? '');

$q = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($humanRunId) . '/questions');
assertTrue($q['status'] === 200, 'questions endpoint should return 200');
assertTrue(count($q['json']['items'] ?? []) === 1, 'human gate run should have pending question');
$qid = (string) ($q['json']['items'][0]['id'] ?? '');

$badAnswer = request('POST', $base . '/api/v1/pipelines/' . rawurlencode($humanRunId) . '/questions/' . rawurlencode($qid) . '/answer', ['answer' => 'Z']);
assertTrue($badAnswer['status'] === 400, 'invalid answer should return 400');

$goodAnswer = request('POST', $base . '/api/v1/pipelines/' . rawurlencode($humanRunId) . '/questions/' . rawurlencode($qid) . '/answer', ['answer' => 'A']);
assertTrue($goodAnswer['status'] === 200, 'valid answer should return 200');

$res = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($humanRunId));
assertTrue(($res['json']['status'] ?? '') === 'completed', 'approved run should complete');

$runningRun = request('POST', $base . '/api/v1/pipelines', [
    'dotSource' => $dot,
    'displayName' => 'Running Run',
    'autoApprove' => false,
]);
assertTrue($runningRun['status'] === 201, 'running run should be created');
$runningId = (string) ($runningRun['json']['id'] ?? '');
$cancel = request('POST', $base . '/api/v1/pipelines/' . rawurlencode($runningId) . '/cancel');
assertTrue($cancel['status'] === 200, 'cancel should return 200');
$deleteRunning = request('DELETE', $base . '/api/v1/pipelines/' . rawurlencode($runningId));
assertTrue($deleteRunning['status'] === 200, 'delete cancelled run should return 200');

$delete = request('DELETE', $base . '/api/v1/pipelines/' . rawurlencode($runId));
assertTrue($delete['status'] === 200, 'delete should return 200');
$deletedGet = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($runId));
assertTrue($deletedGet['status'] === 404, 'deleted run should return 404');

$zipRes = request('GET', $base . '/api/v1/pipelines/' . rawurlencode($iteratedRunId) . '/artifacts.zip');
if (class_exists('ZipArchive')) {
    assertTrue($zipRes['status'] === 200, 'artifacts.zip should return 200 when ZipArchive exists');
} else {
    assertTrue($zipRes['status'] === 500, 'artifacts.zip should return 500 when ZipArchive missing');
}

$status = proc_get_status($proc);
if ($status['running']) {
    proc_terminate($proc);
}
proc_close($proc);

echo "All tests passed\n";
