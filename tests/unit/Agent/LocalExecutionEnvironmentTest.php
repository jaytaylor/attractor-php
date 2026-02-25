<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\Agent;

use Attractor\Agent\Exec\GrepOptions;
use Attractor\Agent\Exec\LocalExecutionEnvironment;
use PHPUnit\Framework\TestCase;

final class LocalExecutionEnvironmentTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/attractor-agent-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function testReadFileWithLineNumbersAndOffsetLimit(): void
    {
        $env = new LocalExecutionEnvironment($this->tmpDir);
        file_put_contents($this->tmpDir . '/a.txt', "line1\nline2\nline3\nline4\n");

        $out = $env->readFile('a.txt', 2, 2);
        $this->assertStringContainsString('2 | line2', $out);
        $this->assertStringContainsString('3 | line3', $out);
        $this->assertStringNotContainsString('1 | line1', $out);
    }

    public function testEditAndWriteFlowWithFileExists(): void
    {
        $env = new LocalExecutionEnvironment($this->tmpDir);
        $env->writeFile('b.txt', 'hello');

        $this->assertTrue($env->fileExists('b.txt'));
        $read = $env->readFile('b.txt');
        $this->assertStringContainsString('1 | hello', $read);
    }

    public function testExecCommandTimeout(): void
    {
        $env = new LocalExecutionEnvironment($this->tmpDir);
        $result = $env->execCommand('sleep 2', 100);

        $this->assertTrue($result->timedOut);
        $this->assertSame(124, $result->exitCode);
        $this->assertStringContainsString('timed out', strtolower($result->stderr));
    }

    public function testGrepAndGlobWork(): void
    {
        $env = new LocalExecutionEnvironment($this->tmpDir);
        file_put_contents($this->tmpDir . '/c.txt', "alpha\nbeta\n");
        file_put_contents($this->tmpDir . '/d.md', "gamma\n");

        $grep = $env->grep('alpha', '.', new GrepOptions());
        $this->assertStringContainsString('alpha', $grep);

        $glob = $env->glob('*.txt', '.');
        $this->assertContains('c.txt', $glob);
    }

    public function testSensitiveEnvVarsFilteredByDefault(): void
    {
        $_ENV['OPENAI_API_KEY'] = 'secret';
        $_ENV['VISIBLE_VALUE'] = 'visible';

        $env = new LocalExecutionEnvironment($this->tmpDir);
        $res = $env->execCommand('env', 2000);

        $this->assertStringContainsString('VISIBLE_VALUE=visible', $res->stdout);
        $this->assertStringNotContainsString('OPENAI_API_KEY=secret', $res->stdout);
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
