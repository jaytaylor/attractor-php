<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Http;

use Attractor\Pipeline\Answer;
use Attractor\Pipeline\Backends\EchoCodergenBackend;
use Attractor\Pipeline\BufferedObserver;
use Attractor\Pipeline\CodergenBackend;
use Attractor\Pipeline\DefaultRunnerFactory;
use Attractor\Pipeline\Human\QueueInterviewer;
use Attractor\Pipeline\RunnerConfig;

final class Server
{
    public function __construct(
        private readonly RunRepository $runs = new RunRepository(),
        private readonly ?CodergenBackend $backend = null,
    ) {
    }

    public function handle(string $method, string $uri, string $rawBody = ''): HttpResponse
    {
        $method = strtoupper($method);
        $parts = parse_url($uri);
        $path = (string) ($parts['path'] ?? '/');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        if ($method === 'POST' && $path === '/run') {
            return $this->runPipeline($rawBody);
        }
        if ($method === 'GET' && $path === '/status') {
            return $this->status((string) ($query['run_id'] ?? ''), (($query['stream'] ?? '0') === '1'));
        }
        if ($method === 'POST' && $path === '/answer') {
            return $this->answer($rawBody);
        }

        return $this->json(404, ['error' => 'not found']);
    }

    private function runPipeline(string $rawBody): HttpResponse
    {
        $payload = $this->decodeJsonBody($rawBody);
        if ($payload === null) {
            return $this->json(400, ['error' => 'invalid JSON body']);
        }

        $dot = $this->resolveDot($payload);
        if ($dot === null || trim($dot) === '') {
            return $this->json(400, ['error' => 'dot is required (inline "dot" or "dot_path")']);
        }

        try {
            $runId = $this->stringOrDefault($payload['run_id'] ?? null, bin2hex(random_bytes(8)));
            $logsRoot = $this->stringOrDefault($payload['logs_root'] ?? null, '.scratch/runs/http-' . $runId);
            $interviewer = new QueueInterviewer($this->answersFromPayload($payload['answers'] ?? null));
            $observer = new BufferedObserver();
            $runner = DefaultRunnerFactory::make($this->backend ?? new EchoCodergenBackend(), $interviewer);

            $graph = $runner->parseDot($dot);
            $outcome = $runner->run($graph, new RunnerConfig(logsRoot: $logsRoot, observer: $observer));
            $this->writeEvents($logsRoot, $observer->all(), false);
            $manifest = $this->readManifest($logsRoot);

            $record = [
                'run_id' => $runId,
                'logs_root' => $logsRoot,
                'status' => $outcome->status,
                'message' => $outcome->message,
                'dot' => $dot,
                'pending_human' => is_array($manifest['pending_human'] ?? null) ? $manifest['pending_human'] : null,
                'updated_at' => date(DATE_ATOM),
            ];
            $this->runs->put($runId, $record);

            return $this->json(200, $record);
        } catch (\Throwable $e) {
            return $this->json(500, ['error' => $e->getMessage()]);
        }
    }

    private function answer(string $rawBody): HttpResponse
    {
        $payload = $this->decodeJsonBody($rawBody);
        if ($payload === null) {
            return $this->json(400, ['error' => 'invalid JSON body']);
        }

        $runId = trim((string) ($payload['run_id'] ?? ''));
        if ($runId === '') {
            return $this->json(400, ['error' => 'run_id is required']);
        }

        $record = $this->runs->get($runId);
        if ($record === null) {
            return $this->json(404, ['error' => 'run not found']);
        }

        $selected = trim((string) ($payload['selected'] ?? ''));
        if ($selected === '') {
            return $this->json(400, ['error' => 'selected is required']);
        }

        try {
            $dot = (string) ($record['dot'] ?? '');
            $logsRoot = (string) ($record['logs_root'] ?? '');
            if ($dot === '' || $logsRoot === '') {
                return $this->json(500, ['error' => 'stored run record is incomplete']);
            }

            $interviewer = new QueueInterviewer([new Answer(selected: [$selected])]);
            $observer = new BufferedObserver();
            $runner = DefaultRunnerFactory::make($this->backend ?? new EchoCodergenBackend(), $interviewer);
            $graph = $runner->parseDot($dot);
            $outcome = $runner->resume($logsRoot, new RunnerConfig(logsRoot: $logsRoot, observer: $observer), $graph);
            $this->writeEvents($logsRoot, $observer->all(), true);
            $manifest = $this->readManifest($logsRoot);

            $updated = [
                'run_id' => $runId,
                'logs_root' => $logsRoot,
                'status' => $outcome->status,
                'message' => $outcome->message,
                'dot' => $dot,
                'pending_human' => is_array($manifest['pending_human'] ?? null) ? $manifest['pending_human'] : null,
                'updated_at' => date(DATE_ATOM),
            ];
            $this->runs->put($runId, $updated);

            return $this->json(200, $updated);
        } catch (\Throwable $e) {
            return $this->json(500, ['error' => $e->getMessage()]);
        }
    }

    private function status(string $runId, bool $stream): HttpResponse
    {
        $runId = trim($runId);
        if ($runId === '') {
            return $this->json(400, ['error' => 'run_id is required']);
        }

        $record = $this->runs->get($runId);
        if ($record === null) {
            return $this->json(404, ['error' => 'run not found']);
        }

        if ($stream) {
            return $this->sse((string) $record['logs_root']);
        }

        $manifest = $this->readManifest((string) $record['logs_root']);
        if ($manifest !== null) {
            $record['manifest'] = $manifest;
            if (isset($manifest['status']) && is_string($manifest['status'])) {
                $record['status'] = $manifest['status'];
            }
        }

        return $this->json(200, $record);
    }

    /** @return list<Answer> */
    private function answersFromPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $answers = [];
        foreach ($payload as $selected) {
            if (!is_string($selected) || trim($selected) === '') {
                continue;
            }
            $answers[] = new Answer(selected: [trim($selected)]);
        }

        return $answers;
    }

    private function resolveDot(array $payload): ?string
    {
        $inline = $payload['dot'] ?? null;
        if (is_string($inline) && trim($inline) !== '') {
            return $inline;
        }

        $dotPath = $payload['dot_path'] ?? null;
        if (!is_string($dotPath) || trim($dotPath) === '' || !is_file($dotPath)) {
            return null;
        }

        return (string) file_get_contents($dotPath);
    }

    /** @return array<string, mixed>|null */
    private function decodeJsonBody(string $rawBody): ?array
    {
        $trimmed = trim($rawBody);
        if ($trimmed === '') {
            return [];
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    /**
     * @param list<\Attractor\Pipeline\PipelineEvent> $events
     */
    private function writeEvents(string $logsRoot, array $events, bool $append): void
    {
        if (!is_dir($logsRoot)) {
            mkdir($logsRoot, 0777, true);
        }

        $path = $logsRoot . '/events.ndjson';
        $mode = $append ? 'ab' : 'wb';
        $fh = fopen($path, $mode);
        if ($fh === false) {
            return;
        }

        foreach ($events as $event) {
            $line = json_encode([
                'type' => $event->type,
                'payload' => $event->payload,
            ], JSON_THROW_ON_ERROR);
            fwrite($fh, $line . PHP_EOL);
        }
        fclose($fh);
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $logsRoot): ?array
    {
        $path = rtrim($logsRoot, '/') . '/manifest.json';
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function sse(string $logsRoot): HttpResponse
    {
        $path = rtrim($logsRoot, '/') . '/events.ndjson';
        if (!is_file($path)) {
            return new HttpResponse(
                status: 200,
                body: "event: end\ndata: {}\n\n",
                headers: [
                    'Content-Type' => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                ],
            );
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $chunks = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $type = is_string($row['type'] ?? null) ? $row['type'] : 'event';
            $payload = $row['payload'] ?? [];
            if (!is_array($payload)) {
                $payload = ['value' => $payload];
            }
            $chunks[] = 'event: ' . $type . "\n" . 'data: ' . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
        }
        $chunks[] = "event: end\ndata: {}\n\n";

        return new HttpResponse(
            status: 200,
            body: implode('', $chunks),
            headers: [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
            ],
        );
    }

    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse(
            status: $status,
            body: json_encode($body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
