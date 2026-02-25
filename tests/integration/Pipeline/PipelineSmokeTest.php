<?php

declare(strict_types=1);

namespace Attractor\Tests\Integration\Pipeline;

use Attractor\Pipeline\Dot\DotParser;
use Attractor\Pipeline\HandlerRegistry;
use Attractor\Pipeline\Handlers\CodergenHandler;
use Attractor\Pipeline\Handlers\ExitHandler;
use Attractor\Pipeline\Handlers\StartHandler;
use Attractor\Pipeline\Human\AutoApproveInterviewer;
use Attractor\Pipeline\Runner;
use Attractor\Pipeline\RunnerConfig;
use Attractor\Pipeline\TransformRegistry;
use Attractor\Pipeline\Validation\Validator;
use Attractor\Tests\Unit\Pipeline\FakeCodergenBackend;
use PHPUnit\Framework\TestCase;

final class PipelineSmokeTest extends TestCase
{
    public function testRunnerEndToEndWithFakeBackend(): void
    {
        $tmp = sys_get_temp_dir() . '/attractor-pipe-smoke-' . uniqid('', true);
        mkdir($tmp, 0777, true);

        try {
            $backend = new FakeCodergenBackend();
            $registry = new HandlerRegistry();
            $registry->register('start', new StartHandler());
            $registry->register('exit', new ExitHandler());
            $registry->register('codergen', new CodergenHandler($backend));

            $runner = new Runner($registry, new TransformRegistry(), new Validator(), new AutoApproveInterviewer());

            $graph = (new DotParser())->parse(<<<'DOT'
            digraph G {
              start [shape=Mdiamond];
              plan [shape=box, prompt="hello"];
              exit [shape=Msquare];
              start -> plan;
              plan -> exit;
            }
            DOT);

            $outcome = $runner->run($graph, new RunnerConfig($tmp));
            $this->assertSame('success', $outcome->status);
            $this->assertFileExists($tmp . '/manifest.json');
        } finally {
            if (is_dir($tmp)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) {
                    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
                }
                rmdir($tmp);
            }
        }
    }
}
