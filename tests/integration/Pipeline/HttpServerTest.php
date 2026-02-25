<?php

declare(strict_types=1);

namespace Attractor\Tests\Integration\Pipeline;

use Attractor\Pipeline\Backends\EchoCodergenBackend;
use Attractor\Pipeline\Http\RunRepository;
use Attractor\Pipeline\Http\Server;
use PHPUnit\Framework\TestCase;

final class HttpServerTest extends TestCase
{
    private string $tmpDir;
    private string $runsDir;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/attractor-http-' . uniqid('', true);
        $this->runsDir = $this->tmpDir . '/run-store';
        mkdir($this->runsDir, 0777, true);
        $this->server = new Server(new RunRepository($this->runsDir), new EchoCodergenBackend());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function testRunStatusAnswerAndSseFlow(): void
    {
        $runId = 'run-http-test';
        $logsRoot = $this->tmpDir . '/logs';
        $dot = <<<'DOT'
        digraph G {
          start [shape=Mdiamond];
          ask [shape=diamond, type="wait.human", question="Choose"];
          yespath [shape=box, prompt="yes"];
          nopath [shape=box, prompt="no"];
          exit [shape=Msquare];
          start -> ask;
          ask -> yespath [label="Yes"];
          ask -> nopath [label="No"];
          yespath -> exit;
          nopath -> exit;
        }
        DOT;

        $runResponse = $this->server->handle('POST', '/run', (string) json_encode([
            'run_id' => $runId,
            'logs_root' => $logsRoot,
            'dot' => $dot,
        ]));
        $this->assertSame(200, $runResponse->status);
        $runBody = json_decode($runResponse->body, true);
        $this->assertSame('waiting', $runBody['status'] ?? null);
        $this->assertSame($runId, $runBody['run_id'] ?? null);

        $statusResponse = $this->server->handle('GET', '/status?run_id=' . $runId);
        $this->assertSame(200, $statusResponse->status);
        $statusBody = json_decode($statusResponse->body, true);
        $this->assertSame('waiting', $statusBody['status'] ?? null);
        $this->assertSame('ask', $statusBody['manifest']['pending_human']['node_id'] ?? null);

        $answerResponse = $this->server->handle('POST', '/answer', (string) json_encode([
            'run_id' => $runId,
            'selected' => 'Yes',
        ]));
        $this->assertSame(200, $answerResponse->status);
        $answerBody = json_decode($answerResponse->body, true);
        $this->assertSame('success', $answerBody['status'] ?? null);
        $this->assertFileExists($logsRoot . '/yespath/status.json');

        $sseResponse = $this->server->handle('GET', '/status?run_id=' . $runId . '&stream=1');
        $this->assertSame(200, $sseResponse->status);
        $this->assertSame('text/event-stream', $sseResponse->headers['Content-Type'] ?? null);
        $this->assertStringContainsString('event: RUN_WAITING', $sseResponse->body);
        $this->assertStringContainsString('event: RUN_END', $sseResponse->body);
    }

    public function testUnknownRunIdReturnsNotFound(): void
    {
        $response = $this->server->handle('GET', '/status?run_id=missing');
        $this->assertSame(404, $response->status);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
