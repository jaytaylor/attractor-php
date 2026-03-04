<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;
use App\Services\DotService;
use App\Services\DotServiceException;
use App\Services\RunRepository;

final class App
{
    private DotService $dotService;
    private RunRepository $runs;

    public function __construct(private readonly string $projectRoot)
    {
        $this->dotService = new DotService();
        $this->runs = new RunRepository($projectRoot, $this->dotService);
    }

    public function run(): void
    {
        $request = Request::fromGlobals();

        if ($request->method === 'OPTIONS') {
            Response::noContent();
        }

        if ($request->path === '/') {
            $this->serveStatic('/index.html');
        }

        if (str_starts_with($request->path, '/assets/')) {
            $this->serveStatic($request->path);
        }

        if ($request->path === '/docs') {
            $this->serveStatic('/docs.html');
        }

        if ($request->path === '/favicon.ico') {
            Response::noContent();
        }

        $this->routeApi($request);

        Response::json(404, ['error' => 'route not found', 'code' => 'NOT_FOUND']);
    }

    private function routeApi(Request $request): void
    {
        $method = $request->method;
        $path = $request->path;

        if ($method === 'GET' && $path === '/api/v1/pipelines') {
            $archiveMode = 'exclude';
            $archived = $request->query['archived'] ?? '';
            $includeArchived = $request->query['includeArchived'] ?? '';
            if ($archived === 'only' || $archived === 'true') {
                $archiveMode = 'only';
            }
            if ($includeArchived === 'true' || $archived === 'all') {
                $archiveMode = 'all';
            }
            Response::json(200, ['items' => $this->runs->listRuns($archiveMode)]);
        }

        if ($method === 'POST' && $path === '/api/v1/pipelines') {
            $dot = (string) ($request->jsonBody['dotSource'] ?? '');
            $validation = $this->dotService->validate($dot);
            if (!$validation['valid']) {
                Response::json(400, ['error' => 'invalid dot source', 'code' => 'BAD_REQUEST', 'diagnostics' => $validation['diagnostics']]);
            }

            $run = $this->runs->createRun($request->jsonBody, null);
            Response::json(201, ['id' => $run['id'], 'status' => $run['status']]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pipelines/([^/]+)$#', $path, $m) === 1) {
            $run = $this->runs->getRun($m[1]);
            if ($run === null) {
                $this->error(404, 'run not found', 'NOT_FOUND');
            }
            Response::json(200, $run);
        }

        if ($method === 'POST' && preg_match('#^/api/v1/pipelines/([^/]+)/cancel$#', $path, $m) === 1) {
            $res = $this->runs->cancelRun($m[1]);
            $this->handleResult($res);
            Response::json(200, ['ok' => true]);
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/pipelines/([^/]+)$#', $path, $m) === 1) {
            $res = $this->runs->deleteRun($m[1]);
            $this->handleResult($res);
            Response::json(200, ['ok' => true]);
        }

        if ($method === 'POST' && preg_match('#^/api/v1/pipelines/([^/]+)/archive$#', $path, $m) === 1) {
            $res = $this->runs->setArchived($m[1], true);
            $this->handleResult($res);
            Response::json(200, ['ok' => true]);
        }

        if ($method === 'POST' && preg_match('#^/api/v1/pipelines/([^/]+)/unarchive$#', $path, $m) === 1) {
            $res = $this->runs->setArchived($m[1], false);
            $this->handleResult($res);
            Response::json(200, ['ok' => true]);
        }

        if ($method === 'GET' && $path === '/api/v1/events') {
            Response::sse($this->runs->streamFrames(null));
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pipelines/([^/]+)/events$#', $path, $m) === 1) {
            $run = $this->runs->getRun($m[1]);
            if ($run === null) {
                $this->error(404, 'run not found', 'NOT_FOUND');
            }
            Response::sse($this->runs->streamFrames($m[1]));
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pipelines/([^/]+)/questions$#', $path, $m) === 1) {
            $run = $this->runs->getRun($m[1]);
            if ($run === null) {
                $this->error(404, 'run not found', 'NOT_FOUND');
            }
            Response::json(200, ['items' => $this->runs->questions($m[1])]);
        }

        if ($method === 'POST' && preg_match('#^/api/v1/pipelines/([^/]+)/questions/([^/]+)/answer$#', $path, $m) === 1) {
            $answer = trim((string) ($request->jsonBody['answer'] ?? ''));
            if ($answer === '') {
                $this->error(400, 'answer is required', 'BAD_REQUEST');
            }
            $res = $this->runs->submitAnswer($m[1], $m[2], $answer);
            $this->handleResult($res);
            Response::json(200, ['ok' => true]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pipelines/([^/]+)/graph$#', $path, $m) === 1) {
            $run = $this->runs->getRun($m[1]);
            if ($run === null) {
                $this->error(404, 'run not found', 'NOT_FOUND');
            }
            try {
                $svg = $this->dotService->renderSvg((string) $run['dotSource']);
            } catch (DotServiceException $e) {
                $this->error($e->httpStatus(), $e->getMessage(), $e->errorCode());
            }
            Response::text(200, $svg, 'image/svg+xml');
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pipelines/([^/]+)/artifacts$#', $path, $m) === 1) {
            $run = $this->runs->getRun($m[1]);
            if ($run === null) {
                $this->error(404, 'run not found', 'NOT_FOUND');
            }
            Response::json(200, ['items' => $this->runs->listArtifacts($m[1])]);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pipelines/([^/]+)/artifacts\.zip$#', $path, $m) === 1) {
            $run = $this->runs->getRun($m[1]);
            if ($run === null) {
                $this->error(404, 'run not found', 'NOT_FOUND');
            }
            $zip = $this->runs->buildArtifactsZip($m[1]);
            if ($zip === null) {
                $this->error(500, 'zip unavailable', 'INTERNAL_ERROR');
            }
            Response::file(200, $zip, 'application/zip');
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pipelines/([^/]+)/artifacts/(.+)$#', $path, $m) === 1) {
            $file = $this->runs->artifactPath($m[1], $m[2]);
            if ($file === null) {
                $this->error(404, 'artifact not found', 'NOT_FOUND');
            }
            $ct = $this->detectContentType($file);
            Response::file(200, $file, $ct);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pipelines/([^/]+)/checkpoint$#', $path, $m) === 1) {
            $c = $this->runs->checkpoint($m[1]);
            if ($c === null) {
                $this->error(404, 'run not found', 'NOT_FOUND');
            }
            Response::json(200, $c);
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pipelines/([^/]+)/context$#', $path, $m) === 1) {
            $c = $this->runs->context($m[1]);
            if ($c === null) {
                $this->error(404, 'run not found', 'NOT_FOUND');
            }
            Response::json(200, $c);
        }

        if ($method === 'POST' && preg_match('#^/api/v1/pipelines/([^/]+)/iterate$#', $path, $m) === 1) {
            $source = $this->runs->getRun($m[1]);
            if ($source === null) {
                $this->error(404, 'run not found', 'NOT_FOUND');
            }
            if ((string) ($source['status'] ?? '') === 'running') {
                $this->error(409, 'source run must be terminal', 'INVALID_STATE');
            }

            $dot = (string) ($request->jsonBody['dotSource'] ?? '');
            $validation = $this->dotService->validate($dot);
            if (!$validation['valid']) {
                $this->error(400, 'invalid dot source', 'BAD_REQUEST', ['diagnostics' => $validation['diagnostics']]);
            }

            $payload = [
                'dotSource' => $dot,
                'displayName' => (string) ($source['displayName'] ?? ''),
                'fileName' => (string) ($source['fileName'] ?? 'pipeline.dot'),
                'simulate' => (bool) ($source['simulate'] ?? false),
                'autoApprove' => (bool) ($source['autoApprove'] ?? true),
                'originalPrompt' => (string) ($request->jsonBody['originalPrompt'] ?? ''),
            ];
            $newRun = $this->runs->createRun($payload, $m[1]);
            Response::json(200, ['newId' => $newRun['id']]);
        }

        if ($method === 'POST' && $path === '/api/v1/dot/validate') {
            $dot = (string) ($request->jsonBody['dotSource'] ?? '');
            $validation = $this->dotService->validate($dot);
            Response::json(200, $validation);
        }

        if ($method === 'POST' && $path === '/api/v1/dot/render') {
            $dot = (string) ($request->jsonBody['dotSource'] ?? '');
            $validation = $this->dotService->validate($dot);
            if (!$validation['valid']) {
                $this->error(400, 'invalid dot source', 'BAD_REQUEST', ['diagnostics' => $validation['diagnostics']]);
            }
            try {
                $svg = $this->dotService->renderSvg($dot);
            } catch (DotServiceException $e) {
                $this->error($e->httpStatus(), $e->getMessage(), $e->errorCode());
            }
            Response::json(200, ['svg' => $svg]);
        }

        if ($method === 'POST' && $path === '/api/v1/dot/generate') {
            $prompt = trim((string) ($request->jsonBody['prompt'] ?? ''));
            if ($prompt === '') {
                $this->error(400, 'prompt is required', 'BAD_REQUEST');
            }
            $options = $this->dotOptions($request);
            try {
                $dot = $this->dotService->generate($prompt, $options);
            } catch (DotServiceException $e) {
                $this->error($e->httpStatus(), $e->getMessage(), $e->errorCode());
            }
            Response::json(200, ['dotSource' => $dot]);
        }

        if ($method === 'POST' && $path === '/api/v1/dot/fix') {
            $dot = trim((string) ($request->jsonBody['dotSource'] ?? ''));
            if ($dot === '') {
                $this->error(400, 'dotSource is required', 'BAD_REQUEST');
            }
            $error = trim((string) ($request->jsonBody['error'] ?? ''));
            $options = $this->dotOptions($request);
            try {
                $fixed = $this->dotService->fix($dot, $error, $options);
            } catch (DotServiceException $e) {
                $this->error($e->httpStatus(), $e->getMessage(), $e->errorCode());
            }
            Response::json(200, ['dotSource' => $fixed]);
        }

        if ($method === 'POST' && $path === '/api/v1/dot/iterate') {
            $base = trim((string) ($request->jsonBody['baseDot'] ?? ''));
            $changes = trim((string) ($request->jsonBody['changes'] ?? ''));
            if ($base === '' || $changes === '') {
                $this->error(400, 'baseDot and changes are required', 'BAD_REQUEST');
            }
            $options = $this->dotOptions($request);
            try {
                $iterated = $this->dotService->iterate($base, $changes, $options);
            } catch (DotServiceException $e) {
                $this->error($e->httpStatus(), $e->getMessage(), $e->errorCode());
            }
            Response::json(200, ['dotSource' => $iterated]);
        }

        if ($method === 'POST' && $path === '/api/v1/dot/generate/stream') {
            $prompt = trim((string) ($request->jsonBody['prompt'] ?? ''));
            if ($prompt === '') {
                $this->error(400, 'prompt is required', 'BAD_REQUEST');
            }
            $options = $this->dotOptions($request);
            try {
                $dot = $this->dotService->generate($prompt, $options);
            } catch (DotServiceException $e) {
                $this->error($e->httpStatus(), $e->getMessage(), $e->errorCode());
            }
            $this->streamDot($dot);
        }

        if ($method === 'POST' && $path === '/api/v1/dot/fix/stream') {
            $dot = trim((string) ($request->jsonBody['dotSource'] ?? ''));
            if ($dot === '') {
                $this->error(400, 'dotSource is required', 'BAD_REQUEST');
            }
            $error = trim((string) ($request->jsonBody['error'] ?? ''));
            $options = $this->dotOptions($request);
            try {
                $fixed = $this->dotService->fix($dot, $error, $options);
            } catch (DotServiceException $e) {
                $this->error($e->httpStatus(), $e->getMessage(), $e->errorCode());
            }
            $this->streamDot($fixed);
        }

        if ($method === 'POST' && $path === '/api/v1/dot/iterate/stream') {
            $base = trim((string) ($request->jsonBody['baseDot'] ?? ''));
            $changes = trim((string) ($request->jsonBody['changes'] ?? ''));
            if ($base === '' || $changes === '') {
                $this->error(400, 'baseDot and changes are required', 'BAD_REQUEST');
            }
            $options = $this->dotOptions($request);
            try {
                $iterated = $this->dotService->iterate($base, $changes, $options);
            } catch (DotServiceException $e) {
                $this->error($e->httpStatus(), $e->getMessage(), $e->errorCode());
            }
            $this->streamDot($iterated);
        }

        // spec-core aliases
        if (str_starts_with($path, '/pipelines')) {
            $apiPath = '/api/v1' . $path;
            $_SERVER['REQUEST_URI'] = $apiPath;
            $aliased = new Request(
                $method,
                $apiPath,
                $request->query,
                $request->headers,
                $request->rawBody,
                $request->jsonBody,
            );
            $this->routeApi($aliased);
        }
    }

    private function streamDot(string $dot): never
    {
        $frames = [];
        foreach ($this->dotService->streamChunks($dot) as $chunk) {
            $frames[] = ['delta' => $chunk];
        }
        $frames[] = ['done' => true, 'dotSource' => $dot];
        Response::sse($frames);
    }

    /**
     * @return array{provider?:string,model?:string}
     */
    private function dotOptions(Request $request): array
    {
        $options = [];
        $provider = trim((string) ($request->jsonBody['provider'] ?? ''));
        if ($provider !== '') {
            $options['provider'] = $provider;
        }

        $model = trim((string) ($request->jsonBody['model'] ?? ''));
        if ($model !== '') {
            $options['model'] = $model;
        }

        return $options;
    }

    private function serveStatic(string $publicPath): void
    {
        $clean = preg_replace('#/+#', '/', $publicPath);
        if (!is_string($clean)) {
            $clean = '/index.html';
        }
        $full = realpath($this->projectRoot . '/public' . $clean);
        $publicRoot = realpath($this->projectRoot . '/public');
        if ($full === false || $publicRoot === false || !str_starts_with($full, $publicRoot)) {
            Response::json(404, ['error' => 'file not found', 'code' => 'NOT_FOUND']);
        }
        if (!is_file($full)) {
            Response::json(404, ['error' => 'file not found', 'code' => 'NOT_FOUND']);
        }

        Response::file(200, $full, $this->detectContentType($full));
    }

    private function detectContentType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'html' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'md' => 'text/markdown; charset=utf-8',
            'txt' => 'text/plain; charset=utf-8',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array{ok:bool,error?:string,code?:string} $result
     */
    private function handleResult(array $result): void
    {
        if (($result['ok'] ?? false) === true) {
            return;
        }

        $code = (string) ($result['code'] ?? 'INTERNAL_ERROR');
        $status = match ($code) {
            'BAD_REQUEST' => 400,
            'NOT_FOUND' => 404,
            'INVALID_STATE' => 409,
            default => 500,
        };

        $this->error($status, (string) ($result['error'] ?? 'error'), $code);
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function error(int $status, string $message, string $code, array $extra = []): never
    {
        Response::json($status, array_merge(['error' => $message, 'code' => $code], $extra));
    }
}
