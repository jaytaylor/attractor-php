<?php

declare(strict_types=1);

namespace AttractorPhp;

use AttractorPhp\Domain\DotService;
use AttractorPhp\Domain\DotGraphParser;
use AttractorPhp\Domain\PipelineService;
use AttractorPhp\Http\ApiError;
use AttractorPhp\Http\Request;
use AttractorPhp\Http\Response;
use AttractorPhp\Http\Router;
use AttractorPhp\Http\Sse;
use AttractorPhp\Llm\DotLlmService;
use AttractorPhp\Llm\TaskLlmService;
use AttractorPhp\Storage\RunStore;

final class App
{
    private Router $router;

    public function __construct(
        private readonly RunStore $store,
        private readonly DotService $dotService,
        private readonly DotLlmService $dotLlmService,
        private readonly PipelineService $pipelineService,
        private readonly string $webDir
    ) {
        $this->router = new Router();
        $this->registerRoutes();
    }

    public static function createDefault(?string $logsRoot = null): self
    {
        $root = dirname(__DIR__);
        $effectiveLogsRoot = $logsRoot ?? (getenv('ATTRACTOR_LOGS_ROOT') ?: $root . '/.scratch/runs');
        $store = new RunStore($effectiveLogsRoot);
        $dot = new DotService();
        $graphParser = new DotGraphParser();
        $dotLlm = new DotLlmService($dot, $graphParser);
        $taskLlm = new TaskLlmService();
        $pipeline = new PipelineService($store, $dot, $graphParser, $taskLlm);
        return new self($store, $dot, $dotLlm, $pipeline, $root . '/web');
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'OPTIONS') {
            return $this->withCors(Response::text('', 204));
        }

        try {
            $response = $this->router->dispatch($request);
            if ($response === null) {
                return $this->withCors($this->error(404, 'NOT_FOUND', 'route not found'));
            }
            return $this->withCors($response);
        } catch (ApiError $e) {
            return $this->withCors($this->error($e->status, $e->errorCode, $e->getMessage()));
        } catch (\Throwable) {
            return $this->withCors($this->error(500, 'INTERNAL_ERROR', 'internal server error'));
        }
    }

    private function registerRoutes(): void
    {
        $this->router->add('GET', '/', fn() => $this->serveAsset('index.html', 'text/html; charset=utf-8'));
        $this->router->add('GET', '/docs', fn() => $this->serveAsset('docs.html', 'text/html; charset=utf-8'));
        $this->router->add('GET', '/app.js', fn() => $this->serveAsset('app.js', 'text/javascript; charset=utf-8'));
        $this->router->add('GET', '/styles.css', fn() => $this->serveAsset('styles.css', 'text/css; charset=utf-8'));
        $this->router->add('GET', '/favicon.svg', fn() => $this->serveAsset('favicon.svg', 'image/svg+xml'));
        $this->router->add('GET', '/favicon.ico', fn() => new Response(204, ['content-type' => 'image/x-icon'], ''));

        $this->router->add('GET', '/api/v1/pipelines', fn(Request $req) => $this->listRuns($req));
        $this->router->add('POST', '/api/v1/pipelines', fn(Request $req) => $this->createRun($req));
        $this->router->add('GET', '/api/v1/pipelines/{id}', fn(Request $req, array $p) => Response::json($this->pipelineService->getRun($p['id'])));
        $this->router->add('POST', '/api/v1/pipelines/{id}/cancel', fn(Request $req, array $p) => Response::json($this->pipelineService->cancel($p['id'])));
        $this->router->add('DELETE', '/api/v1/pipelines/{id}', fn(Request $req, array $p) => $this->deleteRun($p['id']));
        $this->router->add('POST', '/api/v1/pipelines/{id}/archive', fn(Request $req, array $p) => Response::json($this->pipelineService->setArchived($p['id'], true)));
        $this->router->add('POST', '/api/v1/pipelines/{id}/unarchive', fn(Request $req, array $p) => Response::json($this->pipelineService->setArchived($p['id'], false)));
        $this->router->add('GET', '/api/v1/pipelines/{id}/events', fn(Request $req, array $p) => $this->runEventsStream($req, $p['id']));
        $this->router->add('GET', '/api/v1/events', fn(Request $req) => $this->globalEventsStream($req));
        $this->router->add('GET', '/api/v1/pipelines/{id}/questions', fn(Request $req, array $p) => $this->questionsForRun($p['id']));
        $this->router->add('POST', '/api/v1/pipelines/{id}/questions/{qid}/answer', fn(Request $req, array $p) => $this->answerQuestion($req, $p));
        $this->router->add('GET', '/api/v1/pipelines/{id}/graph', fn(Request $req, array $p) => $this->graphForRun($p['id']));
        $this->router->add('GET', '/api/v1/pipelines/{id}/artifacts', fn(Request $req, array $p) => $this->listArtifacts($p['id']));
        $this->router->add('GET', '/api/v1/pipelines/{id}/artifacts.zip', fn(Request $req, array $p) => $this->downloadArtifactsZip($p['id']));
        $this->router->add('GET', '/api/v1/pipelines/{id}/run.zip', fn(Request $req, array $p) => $this->downloadRunZip($p['id']));
        $this->router->add('GET', '/api/v1/pipelines/{id}/artifacts/{path+}', fn(Request $req, array $p) => $this->artifactFile($p['id'], $p['path']));
        $this->router->add('GET', '/api/v1/pipelines/{id}/checkpoint', fn(Request $req, array $p) => $this->checkpointForRun($p['id']));
        $this->router->add('GET', '/api/v1/pipelines/{id}/context', fn(Request $req, array $p) => $this->contextForRun($p['id']));
        $this->router->add('POST', '/api/v1/pipelines/{id}/iterate', fn(Request $req, array $p) => $this->iterateRun($req, $p['id']));

        $this->router->add('POST', '/api/v1/dot/validate', fn(Request $req) => $this->dotValidate($req));
        $this->router->add('POST', '/api/v1/dot/render', fn(Request $req) => $this->dotRender($req));
        $this->router->add('POST', '/api/v1/dot/generate', fn(Request $req) => $this->dotGenerate($req));
        $this->router->add('POST', '/api/v1/dot/generate/stream', fn(Request $req) => $this->dotGenerateStream($req));
        $this->router->add('POST', '/api/v1/dot/fix', fn(Request $req) => $this->dotFix($req));
        $this->router->add('POST', '/api/v1/dot/fix/stream', fn(Request $req) => $this->dotFixStream($req));
        $this->router->add('POST', '/api/v1/dot/iterate', fn(Request $req) => $this->dotIterate($req));
        $this->router->add('POST', '/api/v1/dot/iterate/stream', fn(Request $req) => $this->dotIterateStream($req));

        $this->router->add('POST', '/pipelines', fn(Request $req) => $this->createRun($req));
        $this->router->add('GET', '/pipelines/{id}', fn(Request $req, array $p) => Response::json($this->pipelineService->getRun($p['id'])));
        $this->router->add('GET', '/pipelines/{id}/events', fn(Request $req, array $p) => $this->runEventsStream($req, $p['id']));
        $this->router->add('POST', '/pipelines/{id}/cancel', fn(Request $req, array $p) => Response::json($this->pipelineService->cancel($p['id'])));
        $this->router->add('GET', '/pipelines/{id}/graph', fn(Request $req, array $p) => $this->graphForRun($p['id']));
        $this->router->add('GET', '/pipelines/{id}/artifacts.zip', fn(Request $req, array $p) => $this->downloadArtifactsZip($p['id']));
        $this->router->add('GET', '/pipelines/{id}/run.zip', fn(Request $req, array $p) => $this->downloadRunZip($p['id']));
        $this->router->add('GET', '/pipelines/{id}/questions', fn(Request $req, array $p) => $this->questionsForRun($p['id']));
        $this->router->add('POST', '/pipelines/{id}/questions/{qid}/answer', fn(Request $req, array $p) => $this->answerQuestion($req, $p));
        $this->router->add('GET', '/pipelines/{id}/checkpoint', fn(Request $req, array $p) => $this->checkpointForRun($p['id']));
        $this->router->add('GET', '/pipelines/{id}/context', fn(Request $req, array $p) => $this->contextForRun($p['id']));
    }

    private function serveAsset(string $fileName, string $type): Response
    {
        $path = $this->webDir . '/' . $fileName;
        if (!is_file($path)) {
            return $this->error(404, 'NOT_FOUND', 'asset not found');
        }
        $body = file_get_contents($path) ?: '';
        return new Response(200, ['content-type' => $type], $body);
    }

    private function listRuns(Request $request): Response
    {
        $includeArchived = $request->queryBool('includeArchived', false);
        return Response::json($this->pipelineService->listRuns($includeArchived));
    }

    private function createRun(Request $request): Response
    {
        $created = $this->pipelineService->create($request->jsonBody());
        return Response::json(['id' => $created['id'], 'status' => $created['status']], 201);
    }

    private function deleteRun(string $id): Response
    {
        $this->pipelineService->delete($id);
        return Response::json(['deleted' => true]);
    }

    /** @param array<string,string> $params */
    private function answerQuestion(Request $request, array $params): Response
    {
        $body = $request->jsonBody();
        $answer = (string) ($body['answer'] ?? '');
        if ($answer === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'answer is required');
        }
        $run = $this->pipelineService->answerQuestion($params['id'], $params['qid'], $answer);
        return Response::json($run);
    }

    private function graphForRun(string $runId): Response
    {
        return new Response(200, ['content-type' => 'image/svg+xml; charset=utf-8'], $this->pipelineService->graphSvg($runId));
    }

    private function questionsForRun(string $runId): Response
    {
        $this->pipelineService->tickRun($runId);
        return Response::json($this->store->getQuestions($runId));
    }

    private function listArtifacts(string $runId): Response
    {
        $this->pipelineService->tickRun($runId);
        return Response::json($this->store->listArtifacts($runId));
    }

    private function checkpointForRun(string $runId): Response
    {
        $this->pipelineService->tickRun($runId);
        return Response::json($this->store->readCheckpoint($runId));
    }

    private function contextForRun(string $runId): Response
    {
        $this->pipelineService->tickRun($runId);
        return Response::json($this->store->readContext($runId));
    }

    private function artifactFile(string $runId, string $relativePath): Response
    {
        $this->pipelineService->tickRun($runId);
        $artifact = $this->store->readArtifact($runId, $relativePath);
        $mime = $artifact['isText'] ? 'text/plain; charset=utf-8' : 'application/octet-stream';
        return new Response(200, ['content-type' => $mime], $artifact['content']);
    }

    private function downloadArtifactsZip(string $runId): Response
    {
        $this->pipelineService->tickRun($runId);
        $zipPath = $this->store->createArtifactsZip($runId);
        $contents = file_get_contents($zipPath);
        if ($contents === false) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to read zip file');
        }

        return new Response(200, [
            'content-type' => 'application/zip',
            'content-disposition' => 'attachment; filename="' . $runId . '-artifacts.zip"',
        ], $contents);
    }

    private function downloadRunZip(string $runId): Response
    {
        $this->pipelineService->tickRun($runId);
        $zipPath = $this->store->createRunZip($runId);
        $contents = file_get_contents($zipPath);
        if ($contents === false) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to read zip file');
        }

        return new Response(200, [
            'content-type' => 'application/zip',
            'content-disposition' => 'attachment; filename="' . $runId . '-run.zip"',
        ], $contents);
    }

    private function runEventsStream(Request $request, string $runId): Response
    {
        $this->pipelineService->tickRun($runId);
        $run = $this->store->getRun($runId);
        $sinceTs = max(0, $request->queryInt('sinceTs', 0));
        $body = Sse::comment('keepalive');
        $body .= Sse::frame([
            'runId' => $runId,
            'tsMs' => (int) floor(microtime(true) * 1000),
            'type' => 'Snapshot',
            'payload' => $run,
        ]);
        foreach ($this->store->readEvents($runId, $sinceTs) as $event) {
            $body .= Sse::frame($event);
        }
        return new Response(200, ['content-type' => 'text/event-stream; charset=utf-8'], $body);
    }

    private function globalEventsStream(Request $request): Response
    {
        $this->pipelineService->tickAll();
        $sinceTs = max(0, $request->queryInt('sinceTs', 0));
        $snapshot = [
            'runId' => null,
            'tsMs' => (int) floor(microtime(true) * 1000),
            'type' => 'Snapshot',
            'payload' => $this->store->listRuns(true),
        ];
        $body = Sse::comment('keepalive');
        $body .= Sse::frame($snapshot);
        foreach ($this->store->readGlobalEvents($sinceTs) as $event) {
            $body .= Sse::frame($event);
        }
        return new Response(200, ['content-type' => 'text/event-stream; charset=utf-8'], $body);
    }

    private function dotValidate(Request $request): Response
    {
        $dotSource = (string) ($request->jsonBody()['dotSource'] ?? '');
        if ($dotSource === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'dotSource is required');
        }
        return Response::json($this->dotService->validate($dotSource));
    }

    private function dotRender(Request $request): Response
    {
        $dotSource = (string) ($request->jsonBody()['dotSource'] ?? '');
        if ($dotSource === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'dotSource is required');
        }
        $validation = $this->dotService->validate($dotSource);
        if (!$validation['valid']) {
            throw new ApiError(400, 'BAD_REQUEST', 'invalid DOT source');
        }

        return Response::json(['svg' => $this->dotService->render((string) $validation['dotSource'])]);
    }

    private function dotGenerate(Request $request): Response
    {
        $body = $request->jsonBody();
        $prompt = trim((string) ($body['prompt'] ?? ''));
        if ($prompt === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'prompt is required');
        }
        return Response::json(['dotSource' => $this->dotLlmService->generateFromPrompt($prompt, $this->dotLlmOptions($body))]);
    }

    private function dotGenerateStream(Request $request): Response
    {
        $body = $request->jsonBody();
        $prompt = trim((string) ($body['prompt'] ?? ''));
        if ($prompt === '') {
            return $this->streamError('prompt is required');
        }
        $dot = $this->dotLlmService->generateFromPrompt($prompt, $this->dotLlmOptions($body));
        return $this->streamDotResult($dot);
    }

    private function dotFix(Request $request): Response
    {
        $body = $request->jsonBody();
        $dotSource = (string) ($body['dotSource'] ?? '');
        $error = (string) ($body['error'] ?? '');
        if ($dotSource === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'dotSource is required');
        }

        return Response::json(['dotSource' => $this->dotLlmService->fixDot($dotSource, $error, $this->dotLlmOptions($body))]);
    }

    private function dotFixStream(Request $request): Response
    {
        $body = $request->jsonBody();
        $dotSource = (string) ($body['dotSource'] ?? '');
        $error = (string) ($body['error'] ?? '');
        if ($dotSource === '') {
            return $this->streamError('dotSource is required');
        }

        return $this->streamDotResult($this->dotLlmService->fixDot($dotSource, $error, $this->dotLlmOptions($body)));
    }

    private function dotIterate(Request $request): Response
    {
        $body = $request->jsonBody();
        $baseDot = (string) ($body['baseDot'] ?? '');
        $changes = trim((string) ($body['changes'] ?? ''));
        if ($baseDot === '' || $changes === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'baseDot and changes are required');
        }

        return Response::json(['dotSource' => $this->dotLlmService->iterateDot($baseDot, $changes, $this->dotLlmOptions($body))]);
    }

    private function dotIterateStream(Request $request): Response
    {
        $body = $request->jsonBody();
        $baseDot = (string) ($body['baseDot'] ?? '');
        $changes = trim((string) ($body['changes'] ?? ''));
        if ($baseDot === '' || $changes === '') {
            return $this->streamError('baseDot and changes are required');
        }

        return $this->streamDotResult($this->dotLlmService->iterateDot($baseDot, $changes, $this->dotLlmOptions($body)));
    }

    private function iterateRun(Request $request, string $id): Response
    {
        $body = $request->jsonBody();
        $dotSource = (string) ($body['dotSource'] ?? '');
        $prompt = (string) ($body['originalPrompt'] ?? '');
        if ($dotSource === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'dotSource is required');
        }
        return Response::json($this->pipelineService->iterateRun($id, $dotSource, $prompt));
    }

    private function streamDotResult(string $dotSource): Response
    {
        $body = Sse::comment('keepalive');
        foreach ($this->dotService->streamChunks($dotSource) as $chunk) {
            $body .= Sse::frame(['delta' => $chunk]);
        }
        $body .= Sse::frame(['done' => true, 'dotSource' => $dotSource]);

        return new Response(200, ['content-type' => 'text/event-stream; charset=utf-8'], $body);
    }

    private function streamError(string $message): Response
    {
        $body = Sse::comment('keepalive');
        $body .= Sse::frame(['error' => $message]);
        return new Response(200, ['content-type' => 'text/event-stream; charset=utf-8'], $body);
    }

    /** @param array<string,mixed> $body
      * @return array<string,mixed>
      */
    private function dotLlmOptions(array $body): array
    {
        $options = [];

        $provider = strtolower(trim((string) ($body['provider'] ?? '')));
        if ($provider !== '') {
            $options['provider'] = $provider;
        }

        $model = trim((string) ($body['model'] ?? ''));
        if ($model !== '') {
            $options['model'] = $model;
        }

        $maxTokens = (int) ($body['maxTokens'] ?? 0);
        if ($maxTokens > 0) {
            $options['maxTokens'] = $maxTokens;
        }

        if (isset($body['temperature']) && is_numeric($body['temperature'])) {
            $options['temperature'] = (float) $body['temperature'];
        }

        return $options;
    }

    private function error(int $status, string $code, string $message): Response
    {
        return Response::json([
            'status' => $status,
            'error' => $message,
            'code' => $code,
        ], $status);
    }

    private function withCors(Response $response): Response
    {
        $headers = $response->headers;
        $headers['access-control-allow-origin'] = '*';
        $headers['access-control-allow-methods'] = 'GET, POST, DELETE, OPTIONS';
        $headers['access-control-allow-headers'] = 'content-type';
        return new Response($response->status, $headers, $response->body);
    }
}
