<?php

declare(strict_types=1);

namespace Attractor\Tests\E2E;

use PHPUnit\Framework\TestCase;

final class SmokeE2eTest extends TestCase
{
    public function testCliValidateAndRunBasicPipeline(): void
    {
        $root = dirname(__DIR__, 2);
        $dot = $root . '/examples/pipelines/basic.dot';
        $runDir = sys_get_temp_dir() . '/attractor-e2e-run-' . uniqid('', true);

        try {
            $validate = sprintf('php %s validate %s 2>&1', escapeshellarg($root . '/bin/attractor'), escapeshellarg($dot));
            exec($validate, $validateOut, $validateExit);
            $this->assertSame(0, $validateExit, implode("\n", $validateOut));

            $run = sprintf('php %s run %s %s 2>&1', escapeshellarg($root . '/bin/attractor'), escapeshellarg($dot), escapeshellarg($runDir));
            exec($run, $runOut, $runExit);
            $this->assertSame(0, $runExit, implode("\n", $runOut));
            $this->assertFileExists($runDir . '/manifest.json');
        } finally {
            $this->removeDir($runDir);
        }
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
