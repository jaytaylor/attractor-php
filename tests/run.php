<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Autoload.php';

use AttractorPhp\App;
use AttractorPhp\Http\Request;

final class TestFailed extends RuntimeException
{
}

final class Harness
{
    private int $assertions = 0;

    /** @var list<string> */
    private array $failures = [];

    public function run(string $name, callable $test): void
    {
        try {
            $test();
            fwrite(STDOUT, "PASS {$name}\n");
        } catch (Throwable $t) {
            $this->failures[] = "FAIL {$name}: {$t->getMessage()}";
            fwrite(STDOUT, end($this->failures) . "\n");
        }
    }

    public function assertTrue(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new TestFailed($message);
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new TestFailed($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
        }
    }

    public function assertContains(string $needle, string $haystack, string $message): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new TestFailed($message . ' missing=' . $needle);
        }
    }

    public function summary(): int
    {
        fwrite(STDOUT, "\nAssertions: {$this->assertions}\n");
        if ($this->failures !== []) {
            fwrite(STDERR, implode("\n", $this->failures) . "\n");
            return 1;
        }
        return 0;
    }
}

/** @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>|list<mixed>|null} */
function callApi(App $app, string $method, string $path, ?array $body = null): array
{
    $parts = parse_url($path);
    $routePath = $parts['path'] ?? $path;
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    $request = new Request($method, $routePath, $query, [], $body ? (json_encode($body) ?: '{}') : '');
    $response = $app->handle($request);

    $json = null;
    $contentType = $response->headers['content-type'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode($response->body, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'status' => $response->status,
        'headers' => $response->headers,
        'body' => $response->body,
        'json' => $json,
    ];
}

/** @return list<array<string,mixed>> */
function parseSse(string $raw): array
{
    $events = [];
    foreach (explode("\n\n", trim($raw)) as $frame) {
        $line = trim($frame);
        if (!str_starts_with($line, 'data: ')) {
            continue;
        }
        $payload = json_decode(substr($line, 6), true);
        if (is_array($payload)) {
            $events[] = $payload;
        }
    }
    return $events;
}

/** @param list<string> $terminalStatuses
  * @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>|list<mixed>|null}
  */
function waitForRunStatus(App $app, string $runId, array $terminalStatuses, int $timeoutMs = 2500): array
{
    $deadline = (int) floor(microtime(true) * 1000) + $timeoutMs;
    do {
        $run = callApi($app, 'GET', '/api/v1/pipelines/' . $runId);
        $status = (string) ($run['json']['status'] ?? '');
        if (in_array($status, $terminalStatuses, true)) {
            return $run;
        }
        usleep(50_000);
    } while ((int) floor(microtime(true) * 1000) < $deadline);

    return callApi($app, 'GET', '/api/v1/pipelines/' . $runId);
}

function waitForHttpReady(string $url, int $timeoutMs = 8000): void
{
    $deadline = (int) floor(microtime(true) * 1000) + $timeoutMs;
    do {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 1,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response !== false) {
            return;
        }
        usleep(100_000);
    } while ((int) floor(microtime(true) * 1000) < $deadline);

    throw new RuntimeException('mock LLM server did not start: ' . $url);
}

$logsRoot = dirname(__DIR__) . '/.scratch/tests/SPRINT-002/runs';
if (is_dir($logsRoot)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($logsRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($logsRoot);
}
mkdir($logsRoot, 0777, true);

$mockPort = 19082;
$mockRouter = dirname(__DIR__) . '/tests/fixtures/llm_mock_router.php';
$mockLog = dirname(__DIR__) . '/.scratch/tests/SPRINT-002/mock-llm.ndjson';
if (!is_dir(dirname($mockLog))) {
    mkdir(dirname($mockLog), 0777, true);
}
file_put_contents($mockLog, '');

$mockDescriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', dirname(__DIR__) . '/.scratch/tests/SPRINT-002/mock-llm.stdout.log', 'a'],
    2 => ['file', dirname(__DIR__) . '/.scratch/tests/SPRINT-002/mock-llm.stderr.log', 'a'],
];
$mockEnv = getenv();
if (!is_array($mockEnv)) {
    $mockEnv = [];
}
$mockEnv['ATTRACTOR_MOCK_LLM_LOG'] = $mockLog;
$mockCmd = sprintf('php -S 127.0.0.1:%d %s', $mockPort, escapeshellarg($mockRouter));
$mockProc = proc_open($mockCmd, $mockDescriptors, $mockPipes, dirname(__DIR__), $mockEnv);
if (!is_resource($mockProc)) {
    throw new RuntimeException('failed to start mock LLM server');
}
register_shutdown_function(static function () use (&$mockProc): void {
    if (is_resource($mockProc)) {
        proc_terminate($mockProc);
        proc_close($mockProc);
        $mockProc = null;
    }
});
waitForHttpReady('http://127.0.0.1:' . $mockPort . '/health');

putenv('OPENAI_API_KEY=test-openai-key');
putenv('OPENAI_BASE_URL=http://127.0.0.1:' . $mockPort . '/openai/v1');
putenv('ATTRACTOR_OPENAI_MODEL=test-openai-model');
putenv('ANTHROPIC_API_KEY=test-anthropic-key');
putenv('ANTHROPIC_BASE_URL=http://127.0.0.1:' . $mockPort . '/anthropic/v1');
putenv('ATTRACTOR_ANTHROPIC_MODEL=test-anthropic-model');
putenv('ATTRACTOR_DOT_PROVIDER=openai');

$app = App::createDefault($logsRoot);
$h = new Harness();

$h->run('root and docs served', function () use ($h, $app): void {
    $root = callApi($app, 'GET', '/');
    $h->assertSame(200, $root['status'], '/ should be available');
    $h->assertContains('Attractor PHP', $root['body'], 'root HTML');

    $docs = callApi($app, 'GET', '/docs');
    $h->assertSame(200, $docs['status'], '/docs should be available');
    $h->assertContains('Dashboard Docs', $docs['body'], 'docs HTML');

    $h->assertSame('*', $root['headers']['access-control-allow-origin'] ?? '', 'CORS header should exist');
});

$h->run('dot validate and render', function () use ($h, $app): void {
    $valid = callApi($app, 'POST', '/api/v1/dot/validate', ['dotSource' => 'digraph P { a -> b; }']);
    $h->assertSame(200, $valid['status'], 'valid dot should pass');
    $h->assertTrue((bool) ($valid['json']['valid'] ?? false), 'valid=true');

    $invalid = callApi($app, 'POST', '/api/v1/dot/validate', ['dotSource' => 'digraph P { a -> ; }']);
    $h->assertSame(200, $invalid['status'], 'validate endpoint still 200 with diagnostics');
    $h->assertTrue(!(bool) ($invalid['json']['valid'] ?? true), 'invalid dot should fail');

    $render = callApi($app, 'POST', '/api/v1/dot/render', ['dotSource' => 'digraph P { a -> b; }']);
    $h->assertSame(200, $render['status'], 'render should work');
    $h->assertContains('<svg', (string) ($render['json']['svg'] ?? ''), 'svg payload expected');
});

$h->run('dot generate/fix/iterate sync + stream', function () use ($h, $app): void {
    $generate = callApi($app, 'POST', '/api/v1/dot/generate', ['prompt' => 'Create build pipeline']);
    $h->assertSame(200, $generate['status'], 'generate should work');
    $h->assertContains('digraph', (string) ($generate['json']['dotSource'] ?? ''), 'dot content expected');

    $anthropicGenerate = callApi($app, 'POST', '/api/v1/dot/generate', ['prompt' => 'Create approval pipeline', 'provider' => 'anthropic']);
    $h->assertSame(200, $anthropicGenerate['status'], 'anthropic generate should work');
    $h->assertContains('digraph', (string) ($anthropicGenerate['json']['dotSource'] ?? ''), 'anthropic dot content expected');

    $dogGenerate = callApi($app, 'POST', '/api/v1/dot/generate', ['prompt' => 'create a svg of a dog']);
    $h->assertSame(200, $dogGenerate['status'], 'dog svg prompt should still generate DOT');
    $h->assertContains('digraph', (string) ($dogGenerate['json']['dotSource'] ?? ''), 'dog prompt should normalize to DOT');
    $dogRender = callApi($app, 'POST', '/api/v1/dot/render', ['dotSource' => (string) ($dogGenerate['json']['dotSource'] ?? '')]);
    $h->assertSame(200, $dogRender['status'], 'dog prompt DOT should render');
    $h->assertContains('<svg', (string) ($dogRender['json']['svg'] ?? ''), 'dog prompt render should produce svg');
    $h->assertTrue(strpos((string) ($dogRender['json']['svg'] ?? ''), 'Graph Preview Unavailable') === false, 'dog prompt render should not fall back to error svg');

    $generateMissing = callApi($app, 'POST', '/api/v1/dot/generate', []);
    $h->assertSame(400, $generateMissing['status'], 'missing prompt should fail');

    $stream = callApi($app, 'POST', '/api/v1/dot/generate/stream', ['prompt' => 'Stream this']);
    $h->assertSame(200, $stream['status'], 'stream endpoint should work');
    $h->assertContains('text/event-stream', $stream['headers']['content-type'] ?? '', 'stream content type');
    $events = parseSse($stream['body']);
    $h->assertTrue(count($events) >= 2, 'expect delta + done');
    $h->assertTrue(isset($events[count($events) - 1]['done']), 'last event should be done');

    $fix = callApi($app, 'POST', '/api/v1/dot/fix', ['dotSource' => 'digraph P { a -> ; }', 'error' => 'syntax']);
    $h->assertSame(200, $fix['status'], 'fix should work');

    $fixMissing = callApi($app, 'POST', '/api/v1/dot/fix', ['error' => 'missing']);
    $h->assertSame(400, $fixMissing['status'], 'fix missing dotSource');

    $iterate = callApi($app, 'POST', '/api/v1/dot/iterate', ['baseDot' => 'digraph P { start -> exit; }', 'changes' => 'add approval gate']);
    $h->assertSame(200, $iterate['status'], 'iterate should work');
    $h->assertContains('digraph', (string) ($iterate['json']['dotSource'] ?? ''), 'iterated dot expected');

    $iterateMissing = callApi($app, 'POST', '/api/v1/dot/iterate', ['baseDot' => 'digraph P { start -> exit; }']);
    $h->assertSame(400, $iterateMissing['status'], 'iterate missing changes');
});

$h->run('llm provider traffic observed for both adapters', function () use ($h): void {
    $mockLog = dirname(__DIR__) . '/.scratch/tests/SPRINT-002/mock-llm.ndjson';
    $lines = file($mockLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $providers = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }
        $provider = (string) ($decoded['provider'] ?? '');
        if ($provider !== '') {
            $providers[$provider] = true;
        }
    }

    $h->assertTrue(isset($providers['openai']), 'openai provider should receive traffic');
    $h->assertTrue(isset($providers['anthropic']), 'anthropic provider should receive traffic');
});

$h->run('dot stream endpoints emit terminal error frame for malformed payloads', function () use ($h, $app): void {
    $generate = callApi($app, 'POST', '/api/v1/dot/generate/stream', []);
    $h->assertSame(200, $generate['status'], 'stream generate malformed payload should still stream terminal error');
    $generateEvents = parseSse($generate['body']);
    $h->assertTrue(count($generateEvents) >= 1, 'stream generate should emit at least one frame');
    $h->assertContains('required', (string) ($generateEvents[count($generateEvents) - 1]['error'] ?? ''), 'stream generate terminal error expected');
    $h->assertTrue(!isset($generateEvents[count($generateEvents) - 1]['done']), 'error frame must not be a done frame');

    $fix = callApi($app, 'POST', '/api/v1/dot/fix/stream', ['error' => 'missing dot']);
    $h->assertSame(200, $fix['status'], 'stream fix malformed payload should stream terminal error');
    $fixEvents = parseSse($fix['body']);
    $h->assertTrue(count($fixEvents) >= 1, 'stream fix should emit at least one frame');
    $h->assertContains('dotSource is required', (string) ($fixEvents[count($fixEvents) - 1]['error'] ?? ''), 'stream fix terminal error expected');

    $iterate = callApi($app, 'POST', '/api/v1/dot/iterate/stream', ['baseDot' => 'digraph X { start -> exit; }']);
    $h->assertSame(200, $iterate['status'], 'stream iterate malformed payload should stream terminal error');
    $iterateEvents = parseSse($iterate['body']);
    $h->assertTrue(count($iterateEvents) >= 1, 'stream iterate should emit at least one frame');
    $h->assertContains('baseDot and changes are required', (string) ($iterateEvents[count($iterateEvents) - 1]['error'] ?? ''), 'stream iterate terminal error expected');
});

$h->run('pipeline create/get/list', function () use ($h, $app): void {
    $create = callApi($app, 'POST', '/api/v1/pipelines', [
        'dotSource' => 'digraph P { start -> plan; plan -> implement; implement -> exit; }',
        'displayName' => 'Run One',
        'simulate' => true,
    ]);
    $h->assertSame(201, $create['status'], 'create should return 201');
    $runId = (string) ($create['json']['id'] ?? '');
    $h->assertTrue($runId !== '', 'run id expected');

    $get = callApi($app, 'GET', '/api/v1/pipelines/' . $runId);
    $h->assertSame(200, $get['status'], 'get run should work');
    $h->assertSame($runId, (string) ($get['json']['id'] ?? ''), 'get run id match');
    $h->assertSame('completed', (string) ($get['json']['status'] ?? ''), 'run should complete in simulation');

    $list = callApi($app, 'GET', '/api/v1/pipelines');
    $h->assertSame(200, $list['status'], 'list should work');
    $h->assertTrue(count($list['json']) >= 1, 'at least one run listed');

    $badCreate = callApi($app, 'POST', '/api/v1/pipelines', ['dotSource' => '']);
    $h->assertSame(400, $badCreate['status'], 'empty dot should fail');
    $h->assertSame(400, (int) ($badCreate['json']['status'] ?? 0), 'error envelope should include status');
    $h->assertSame('BAD_REQUEST', (string) ($badCreate['json']['code'] ?? ''), 'error envelope should include code');
    $h->assertTrue(((string) ($badCreate['json']['error'] ?? '')) !== '', 'error envelope should include message');
});

$h->run('non-sim run progresses to completion over time', function () use ($h, $app): void {
    $create = callApi($app, 'POST', '/api/v1/pipelines', [
        'dotSource' => 'digraph N { start -> plan; plan -> implement; implement -> test; test -> exit; }',
        'displayName' => 'Async Progression',
        'simulate' => false,
    ]);
    $runId = (string) ($create['json']['id'] ?? '');
    $h->assertTrue($runId !== '', 'run id expected');

    $initial = callApi($app, 'GET', '/api/v1/pipelines/' . $runId);
    $h->assertSame('running', (string) ($initial['json']['status'] ?? ''), 'run should start as running');

    $terminal = waitForRunStatus($app, $runId, ['completed']);
    $h->assertSame('completed', (string) ($terminal['json']['status'] ?? ''), 'non-sim run should complete after progression');
});

$h->run('run artifacts/graph/checkpoint/context', function () use ($h, $app): void {
    $create = callApi($app, 'POST', '/api/v1/pipelines', [
        'dotSource' => 'digraph G { start -> implement; implement -> exit; }',
        'displayName' => 'Artifacts Run',
    ]);
    $runId = (string) ($create['json']['id'] ?? '');
    waitForRunStatus($app, $runId, ['completed']);

    $graph = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/graph');
    $h->assertSame(200, $graph['status'], 'graph should work');
    $h->assertContains('<svg', $graph['body'], 'graph body should be svg');

    $artifacts = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/artifacts');
    $h->assertSame(200, $artifacts['status'], 'artifact list should work');
    $h->assertTrue(count($artifacts['json']) >= 1, 'artifact files expected');

    $firstPath = (string) ($artifacts['json'][0]['path'] ?? '');
    $artifact = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/artifacts/' . $firstPath);
    $h->assertSame(200, $artifact['status'], 'artifact fetch should work');

    $traversal = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/artifacts/../manifest.json');
    $h->assertTrue(in_array($traversal['status'], [400, 404], true), 'path traversal must be rejected');

    $zip = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/artifacts.zip');
    $h->assertSame(200, $zip['status'], 'zip should work');
    $h->assertContains('application/zip', $zip['headers']['content-type'] ?? '', 'zip content type');

    $checkpoint = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/checkpoint');
    $h->assertSame(200, $checkpoint['status'], 'checkpoint should work');
    $h->assertTrue(isset($checkpoint['json']['current_node']), 'checkpoint shape');

    $context = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/context');
    $h->assertSame(200, $context['status'], 'context should work');
    $h->assertTrue(isset($context['json']['graph.goal']), 'context shape');

    $missingContext = callApi($app, 'GET', '/api/v1/pipelines/does-not-exist/context');
    $h->assertSame(404, $missingContext['status'], 'missing run context should 404');
});

$h->run('sse snapshot then events (run + global)', function () use ($h, $app): void {
    $create = callApi($app, 'POST', '/api/v1/pipelines', [
        'dotSource' => 'digraph S { start -> implement; implement -> exit; }',
        'displayName' => 'SSE Run',
    ]);
    $runId = (string) ($create['json']['id'] ?? '');

    $perRun = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/events');
    $h->assertSame(200, $perRun['status'], 'run sse should work');
    $runEvents = parseSse($perRun['body']);
    $h->assertSame('Snapshot', (string) ($runEvents[0]['type'] ?? ''), 'first per-run event should be snapshot');

    $global = callApi($app, 'GET', '/api/v1/events');
    $h->assertSame(200, $global['status'], 'global sse should work');
    $globalEvents = parseSse($global['body']);
    $h->assertSame('Snapshot', (string) ($globalEvents[0]['type'] ?? ''), 'first global event should be snapshot');
});

$h->run('sse replay cursor filtering and normalization', function () use ($h, $app): void {
    $create = callApi($app, 'POST', '/api/v1/pipelines', [
        'dotSource' => 'digraph C { start -> plan; plan -> exit; }',
        'displayName' => 'Cursor Run',
        'simulate' => true,
    ]);
    $runId = (string) ($create['json']['id'] ?? '');

    $all = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/events');
    $allEvents = parseSse($all['body']);
    $h->assertSame('Snapshot', (string) ($allEvents[0]['type'] ?? ''), 'cursor baseline starts with snapshot');

    $maxTs = 0;
    foreach ($allEvents as $event) {
        if ((string) ($event['type'] ?? '') === 'Snapshot') {
            continue;
        }
        $maxTs = max($maxTs, (int) ($event['tsMs'] ?? 0));
    }
    $h->assertTrue($maxTs > 0, 'expect event timestamps');

    $replay = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/events?sinceTs=' . $maxTs);
    $replayEvents = parseSse($replay['body']);
    $h->assertSame('Snapshot', (string) ($replayEvents[0]['type'] ?? ''), 'replay response starts with snapshot');
    $nonSnapshotReplay = array_values(array_filter($replayEvents, static fn(array $event): bool => (string) ($event['type'] ?? '') !== 'Snapshot'));
    $h->assertSame(0, count($nonSnapshotReplay), 'replay should exclude events at or before cursor');

    $malformed = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/events?sinceTs=abc');
    $malformedEvents = parseSse($malformed['body']);
    $h->assertSame('Snapshot', (string) ($malformedEvents[0]['type'] ?? ''), 'malformed cursor is normalized and still snapshot-first');
    $nonSnapshotMalformed = array_values(array_filter($malformedEvents, static fn(array $event): bool => (string) ($event['type'] ?? '') !== 'Snapshot'));
    $h->assertTrue(count($nonSnapshotMalformed) >= 1, 'malformed cursor normalization should include deltas');

    $futureGlobal = callApi($app, 'GET', '/api/v1/events?sinceTs=9999999999999');
    $futureEvents = parseSse($futureGlobal['body']);
    $h->assertSame('Snapshot', (string) ($futureEvents[0]['type'] ?? ''), 'future cursor still snapshot-first');
    $futureDeltas = array_values(array_filter($futureEvents, static fn(array $event): bool => (string) ($event['type'] ?? '') !== 'Snapshot'));
    $h->assertSame(0, count($futureDeltas), 'future cursor should return empty incremental set');
});

$h->run('human gate question and answer flow', function () use ($h, $app): void {
    $create = callApi($app, 'POST', '/api/v1/pipelines', [
        'dotSource' => 'digraph H { start -> review_gate; review_gate -> exit; }',
        'displayName' => 'Human Run',
        'autoApprove' => false,
    ]);
    $runId = (string) ($create['json']['id'] ?? '');

    $run = callApi($app, 'GET', '/api/v1/pipelines/' . $runId);
    $h->assertSame('waiting_human', (string) ($run['json']['status'] ?? ''), 'human gate should pause run');

    $questions = callApi($app, 'GET', '/api/v1/pipelines/' . $runId . '/questions');
    $h->assertSame(200, $questions['status'], 'question list should work');
    $qid = (string) ($questions['json'][0]['id'] ?? '');
    $h->assertTrue($qid !== '', 'question id expected');

    $badAnswer = callApi($app, 'POST', '/api/v1/pipelines/' . $runId . '/questions/' . $qid . '/answer', ['answer' => 'Z']);
    $h->assertSame(400, $badAnswer['status'], 'invalid answer option should fail');

    $missingQ = callApi($app, 'POST', '/api/v1/pipelines/' . $runId . '/questions/nope/answer', ['answer' => 'A']);
    $h->assertSame(404, $missingQ['status'], 'unknown question should fail');

    $ok = callApi($app, 'POST', '/api/v1/pipelines/' . $runId . '/questions/' . $qid . '/answer', ['answer' => 'A']);
    $h->assertSame(200, $ok['status'], 'answer should resume run');

    $final = callApi($app, 'GET', '/api/v1/pipelines/' . $runId);
    $h->assertSame('completed', (string) ($final['json']['status'] ?? ''), 'run should complete after answer');
});

$h->run('running status actions and state guards', function () use ($h, $app): void {
    $waitingCreate = callApi($app, 'POST', '/api/v1/pipelines', [
        'dotSource' => 'digraph WH { start -> review_gate; review_gate -> exit; }',
        'displayName' => 'Waiting Human',
        'autoApprove' => false,
    ]);
    $waitingId = (string) ($waitingCreate['json']['id'] ?? '');
    $archiveWaiting = callApi($app, 'POST', '/api/v1/pipelines/' . $waitingId . '/archive');
    $h->assertSame(409, $archiveWaiting['status'], 'archive waiting_human should fail');

    $create = callApi($app, 'POST', '/api/v1/pipelines', [
        'dotSource' => 'digraph R { start -> STATUS_RUNNING; STATUS_RUNNING -> exit; }',
        'displayName' => 'Running Run',
    ]);
    $runId = (string) ($create['json']['id'] ?? '');

    $run = callApi($app, 'GET', '/api/v1/pipelines/' . $runId);
    $h->assertSame('running', (string) ($run['json']['status'] ?? ''), 'run should remain running');

    $deleteRunning = callApi($app, 'DELETE', '/api/v1/pipelines/' . $runId);
    $h->assertSame(409, $deleteRunning['status'], 'delete running should fail');

    $archiveRunning = callApi($app, 'POST', '/api/v1/pipelines/' . $runId . '/archive');
    $h->assertSame(409, $archiveRunning['status'], 'archive running should fail');

    $cancel = callApi($app, 'POST', '/api/v1/pipelines/' . $runId . '/cancel');
    $h->assertSame(200, $cancel['status'], 'cancel running should work');

    $cancelAgain = callApi($app, 'POST', '/api/v1/pipelines/' . $runId . '/cancel');
    $h->assertSame(409, $cancelAgain['status'], 'cancel terminal should fail');

    $archive = callApi($app, 'POST', '/api/v1/pipelines/' . $runId . '/archive');
    $h->assertSame(200, $archive['status'], 'archive terminal should work');

    $archiveAgain = callApi($app, 'POST', '/api/v1/pipelines/' . $runId . '/archive');
    $h->assertSame(409, $archiveAgain['status'], 'archive archived should fail');

    $listDefault = callApi($app, 'GET', '/api/v1/pipelines');
    $listedDefaultIds = array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $listDefault['json']);
    $h->assertTrue(!in_array($runId, $listedDefaultIds, true), 'archived run hidden by default list');

    $listAll = callApi($app, 'GET', '/api/v1/pipelines?includeArchived=true');
    $listedAllIds = array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $listAll['json']);
    $h->assertTrue(in_array($runId, $listedAllIds, true), 'archived run present with includeArchived');

    $unarchive = callApi($app, 'POST', '/api/v1/pipelines/' . $runId . '/unarchive');
    $h->assertSame(200, $unarchive['status'], 'unarchive should work');

    $unarchiveAgain = callApi($app, 'POST', '/api/v1/pipelines/' . $runId . '/unarchive');
    $h->assertSame(409, $unarchiveAgain['status'], 'unarchive non-archived should fail');

    $deleteFinal = callApi($app, 'DELETE', '/api/v1/pipelines/' . $runId);
    $h->assertSame(200, $deleteFinal['status'], 'delete terminal should work');

    $missingDelete = callApi($app, 'DELETE', '/api/v1/pipelines/' . $runId);
    $h->assertSame(404, $missingDelete['status'], 'delete missing run should 404');
});

$h->run('iterate run lineage preserved', function () use ($h, $app): void {
    $create = callApi($app, 'POST', '/api/v1/pipelines', [
        'dotSource' => 'digraph I { start -> implement; implement -> exit; }',
        'displayName' => 'Iter Source',
    ]);
    $sourceId = (string) ($create['json']['id'] ?? '');
    waitForRunStatus($app, $sourceId, ['completed']);

    $source = callApi($app, 'GET', '/api/v1/pipelines/' . $sourceId);
    $sourceFamily = (string) ($source['json']['familyId'] ?? '');

    $iter = callApi($app, 'POST', '/api/v1/pipelines/' . $sourceId . '/iterate', [
        'dotSource' => 'digraph I2 { start -> review_gate; review_gate -> exit; }',
        'originalPrompt' => 'add gate',
    ]);
    $h->assertSame(200, $iter['status'], 'iterate endpoint should work');
    $newId = (string) ($iter['json']['newId'] ?? '');
    $h->assertTrue($newId !== '' && $newId !== $sourceId, 'new run id should differ');

    $newRun = callApi($app, 'GET', '/api/v1/pipelines/' . $newId);
    $h->assertSame($sourceFamily, (string) ($newRun['json']['familyId'] ?? ''), 'family id should be preserved');

    $sourceAfter = callApi($app, 'GET', '/api/v1/pipelines/' . $sourceId);
    $h->assertSame((string) ($source['json']['status'] ?? ''), (string) ($sourceAfter['json']['status'] ?? ''), 'source run should remain unchanged');

    $runningCreate = callApi($app, 'POST', '/api/v1/pipelines', [
        'dotSource' => 'digraph IR { start -> STATUS_RUNNING; STATUS_RUNNING -> exit; }',
        'displayName' => 'Iter Running',
    ]);
    $runningId = (string) ($runningCreate['json']['id'] ?? '');
    $iterRunning = callApi($app, 'POST', '/api/v1/pipelines/' . $runningId . '/iterate', [
        'dotSource' => 'digraph X { start -> exit; }',
        'originalPrompt' => 'nope',
    ]);
    $h->assertSame(409, $iterRunning['status'], 'iterating running run must fail');
});

$h->run('spec aliases behave like v1', function () use ($h, $app): void {
    $create = callApi($app, 'POST', '/pipelines', ['dotSource' => 'digraph A { start -> exit; }']);
    $h->assertSame(201, $create['status'], 'alias create should work');
    $id = (string) ($create['json']['id'] ?? '');

    $get = callApi($app, 'GET', '/pipelines/' . $id);
    $h->assertSame(200, $get['status'], 'alias get should work');

    $events = callApi($app, 'GET', '/pipelines/' . $id . '/events');
    $h->assertSame(200, $events['status'], 'alias events should work');

    $checkpoint = callApi($app, 'GET', '/pipelines/' . $id . '/checkpoint');
    $h->assertSame(200, $checkpoint['status'], 'alias checkpoint should work');

    $context = callApi($app, 'GET', '/pipelines/' . $id . '/context');
    $h->assertSame(200, $context['status'], 'alias context should work');
});

$exit = $h->summary();
$artifactDir = dirname(__DIR__) . '/.scratch/verification/SPRINT-002/phase4/backend-tests';
if (!is_dir($artifactDir)) {
    mkdir($artifactDir, 0777, true);
}
file_put_contents($artifactDir . '/test-summary.txt', 'exit=' . $exit . "\n");

if (is_resource($mockProc)) {
    proc_terminate($mockProc);
    proc_close($mockProc);
}

exit($exit);
