<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\Pipeline;

use Attractor\Pipeline\Dot\DotParser;
use Attractor\Pipeline\HandlerRegistry;
use Attractor\Pipeline\Handlers\CodergenHandler;
use Attractor\Pipeline\Handlers\ConditionalHandler;
use Attractor\Pipeline\Handlers\ExitHandler;
use Attractor\Pipeline\Handlers\FanInHandler;
use Attractor\Pipeline\Handlers\ManagerLoopHandler;
use Attractor\Pipeline\Handlers\ParallelHandler;
use Attractor\Pipeline\Handlers\StartHandler;
use Attractor\Pipeline\Handlers\ToolHandler;
use Attractor\Pipeline\Handlers\WaitHumanHandler;
use Attractor\Pipeline\Human\AutoApproveInterviewer;
use Attractor\Pipeline\Human\QueueInterviewer;
use Attractor\Pipeline\Answer;
use Attractor\Pipeline\Runner;
use Attractor\Pipeline\RunnerConfig;
use Attractor\Pipeline\Runtime\Outcome;
use Attractor\Pipeline\TransformRegistry;
use Attractor\Pipeline\Transforms\VariableExpansionTransform;
use Attractor\Pipeline\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/attractor-runner-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function testRunSimplePipelineAndWriteArtifacts(): void
    {
        $backend = new FakeCodergenBackend();
        $runner = $this->newRunner($backend, new AutoApproveInterviewer());

        $dot = <<<'DOT'
        digraph G {
          graph [goal="ship"];
          start [shape=Mdiamond];
          plan [shape=box, prompt="do $goal"];
          exit [shape=Msquare];
          start -> plan;
          plan -> exit;
        }
        DOT;

        $graph = $runner->parseDot($dot);
        $outcome = $runner->run($graph, new RunnerConfig($this->tmpDir));

        $this->assertSame('success', $outcome->status);
        $this->assertFileExists($this->tmpDir . '/manifest.json');
        $this->assertFileExists($this->tmpDir . '/plan/prompt.md');
        $this->assertFileExists($this->tmpDir . '/plan/response.md');
        $this->assertFileExists($this->tmpDir . '/plan/status.json');
        $this->assertStringContainsString('do ship', (string) file_get_contents($this->tmpDir . '/plan/prompt.md'));
    }

    public function testGoalGateUnsatisfiedFailsAtExitWithoutRetryTarget(): void
    {
        $backend = new FakeCodergenBackend(Outcome::fail('not done'));
        $runner = $this->newRunner($backend, new AutoApproveInterviewer());

        $dot = <<<'DOT'
        digraph G {
          start [shape=Mdiamond];
          plan [shape=box, prompt="x", goal_gate=true];
          exit [shape=Msquare];
          start -> plan;
          plan -> exit;
        }
        DOT;

        $graph = $runner->parseDot($dot);
        $outcome = $runner->run($graph, new RunnerConfig($this->tmpDir));
        $this->assertSame('fail', $outcome->status);
    }

    public function testWaitHumanPreferredLabelInfluencesEdgeSelection(): void
    {
        $backend = new FakeCodergenBackend();
        $interviewer = new QueueInterviewer([new Answer(selected: ['No'])]);
        $runner = $this->newRunner($backend, $interviewer);

        $dot = <<<'DOT'
        digraph G {
          start [shape=Mdiamond];
          ask [shape=diamond, type="wait.human"];
          yespath [shape=box, prompt="y"];
          nopath [shape=box, prompt="n"];
          exit [shape=Msquare];
          start -> ask;
          ask -> yespath [label="Yes"];
          ask -> nopath [label="No"];
          yespath -> exit;
          nopath -> exit;
        }
        DOT;

        $graph = $runner->parseDot($dot);
        $outcome = $runner->run($graph, new RunnerConfig($this->tmpDir));
        $this->assertSame('success', $outcome->status);
        $this->assertFileExists($this->tmpDir . '/nopath/status.json');
    }

    public function testFailureRoutingOrderFailEdgeThenTerminate(): void
    {
        $backend = new FakeCodergenBackend(Outcome::fail('boom'), true);
        $runner = $this->newRunner($backend, new AutoApproveInterviewer());

        $dot = <<<'DOT'
        digraph G {
          start [shape=Mdiamond];
          plan [shape=box, prompt="x", max_retries=0];
          recover [shape=box, prompt="recover"];
          exit [shape=Msquare];
          start -> plan;
          plan -> recover [label="fail"];
          recover -> exit;
        }
        DOT;

        $graph = $runner->parseDot($dot);
        $outcome = $runner->run($graph, new RunnerConfig($this->tmpDir));

        $this->assertSame('success', $outcome->status);
        $this->assertFileExists($this->tmpDir . '/recover/status.json');
    }

    public function testResumeLoadsCheckpointAndAppliesFidelityDowngrade(): void
    {
        $backend = new FakeCodergenBackend();
        $runner = $this->newRunner($backend, new AutoApproveInterviewer());

        $dot = <<<'DOT'
        digraph G {
          start [shape=Mdiamond];
          plan [shape=box, prompt="x", fidelity="full"];
          exit [shape=Msquare];
          start -> plan;
          plan -> exit;
        }
        DOT;

        $graph = $runner->parseDot($dot);
        $runner->run($graph, new RunnerConfig($this->tmpDir));

        $resumed = $runner->resume($this->tmpDir, new RunnerConfig($this->tmpDir . '-resume'), $graph);
        $this->assertSame('success', $resumed->status);
        $this->assertSame('summary:high', $graph->nodes['exit']->attrs['fidelity']);
    }

    private function newRunner(FakeCodergenBackend $backend, $interviewer): Runner
    {
        $registry = new HandlerRegistry();
        $registry->register('start', new StartHandler());
        $registry->register('exit', new ExitHandler());
        $registry->register('codergen', new CodergenHandler($backend));
        $registry->register('wait.human', new WaitHumanHandler($interviewer));
        $registry->register('conditional', new ConditionalHandler());
        $registry->register('parallel', new ParallelHandler());
        $registry->register('fan.in', new FanInHandler());
        $registry->register('tool', new ToolHandler());
        $registry->register('manager.loop', new ManagerLoopHandler());

        $transforms = new TransformRegistry();
        $transforms->register(new VariableExpansionTransform());

        return new Runner($registry, $transforms, new Validator(), $interviewer);
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
