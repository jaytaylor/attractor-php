<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\Agent;

use Attractor\Agent\Exec\LocalExecutionEnvironment;
use Attractor\Agent\Profiles\AnthropicProfile;
use Attractor\Agent\Profiles\GeminiProfile;
use Attractor\Agent\Profiles\OpenAIProfile;
use Attractor\Agent\ProjectDocs;
use Attractor\Agent\Tools\CoreTools;
use Attractor\Agent\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/attractor-profile-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/AGENTS.md', 'global instructions');
        file_put_contents($this->tmpDir . '/CLAUDE.md', 'anthropic instructions');
        file_put_contents($this->tmpDir . '/GEMINI.md', 'gemini instructions');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir . '/AGENTS.md');
        @unlink($this->tmpDir . '/CLAUDE.md');
        @unlink($this->tmpDir . '/GEMINI.md');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testProviderSpecificDocDiscovery(): void
    {
        $anthropic = ProjectDocs::discover($this->tmpDir, 'anthropic');
        $this->assertStringContainsString('CLAUDE.md', $anthropic->asPromptBlock());
        $this->assertStringNotContainsString('GEMINI.md', $anthropic->asPromptBlock());

        $gemini = ProjectDocs::discover($this->tmpDir, 'gemini');
        $this->assertStringContainsString('GEMINI.md', $gemini->asPromptBlock());
        $this->assertStringNotContainsString('CLAUDE.md', $gemini->asPromptBlock());
    }

    public function testProfilePromptsIncludeEnvironmentAndTools(): void
    {
        $registry = new ToolRegistry();
        CoreTools::register($registry, 10000);

        $env = new LocalExecutionEnvironment($this->tmpDir);
        $docs = ProjectDocs::discover($this->tmpDir, 'openai');
        $prompt = (new OpenAIProfile('gpt-5.2', $registry))->buildSystemPrompt($env, $docs);

        $this->assertStringContainsString('cwd:', $prompt);
        $this->assertStringContainsString('read_file', $prompt);
        $this->assertStringContainsString('AGENTS.md', $prompt);
    }

    public function testProviderProfilesExposeExpectedBasics(): void
    {
        $registry = new ToolRegistry();
        CoreTools::register($registry, 10000);

        $this->assertSame('openai', (new OpenAIProfile('gpt-5.2', $registry))->id());
        $this->assertSame('anthropic', (new AnthropicProfile('claude-sonnet-4-5', $registry))->id());
        $this->assertSame('gemini', (new GeminiProfile('gemini-2.0-flash', $registry))->id());
    }
}
