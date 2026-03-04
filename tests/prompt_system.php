<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Agent\Exec\ExecResult;
use App\Agent\Exec\ExecutionEnvironment;
use App\Agent\ProjectDocDiscovery;
use App\Agent\Prompts\ClaudePromptEnvironment;
use App\Agent\Prompts\ClaudePromptGitInfo;
use App\Agent\Prompts\ClaudeSystemPrompt;
use App\Agent\Prompts\CodexSystemPrompt;
use App\Agent\Profiles\AnthropicProfile;
use App\Agent\Profiles\GeminiProfile;
use App\Agent\Profiles\GitContext;
use App\Agent\Profiles\OpenAIProfile;
use App\Agent\Session;

final class FakeExecutionEnvironment implements ExecutionEnvironment
{
    /**
     * @param array<string,ExecResult> $commandResponses
     */
    public function __construct(
        private readonly string $workingDirectory,
        private readonly array $commandResponses = [],
    ) {
    }

    public function workingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function platform(): string
    {
        return 'darwin';
    }

    public function osVersion(): string
    {
        return 'Darwin 24.0.0';
    }

    public function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    public function readFile(string $path, ?int $offset = null, ?int $limit = null): string
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('read failed');
        }

        if ($offset !== null || $limit !== null) {
            $start = $offset ?? 0;
            if ($limit !== null) {
                return substr($content, $start, $limit);
            }
            return substr($content, $start);
        }

        return $content;
    }

    public function execCommand(string $command, int $timeoutMs, ?string $workingDir = null): ExecResult
    {
        return $this->commandResponses[$command] ?? new ExecResult(1, '', 'not found');
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ASSERT FAILED: {$message}\n");
        exit(1);
    }
}

function assertContains(string $haystack, string $needle, string $message): void
{
    assertTrue(str_contains($haystack, $needle), $message . " (missing: {$needle})");
}

function makeTempDir(): string
{
    $base = dirname(__DIR__) . '/.scratch/tests/prompt_system';
    @mkdir($base, 0777, true);
    $path = $base . '/' . bin2hex(random_bytes(6));
    mkdir($path, 0777, true);
    return $path;
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

$root = dirname(__DIR__);

$sumLines = file($root . '/resources/prompts/codex/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
assertTrue(is_array($sumLines), 'SHA256SUMS must be readable');
foreach ($sumLines as $line) {
    $parts = preg_split('/\s+/', trim($line));
    assertTrue(is_array($parts) && count($parts) >= 2, 'invalid SHA256SUMS line format');
    $expected = $parts[0];
    $file = $parts[1];
    $path = $root . '/resources/prompts/codex/' . $file;
    assertTrue(is_file($path), 'missing codex prompt file: ' . $file);
    $actual = hash_file('sha256', $path);
    assertTrue($actual === $expected, 'hash mismatch for ' . $file);
}

$basePromptFile = file_get_contents($root . '/resources/prompts/codex/prompt.md');
assertTrue(is_string($basePromptFile), 'prompt.md read failed');
assertTrue(CodexSystemPrompt::basePrompt() === $basePromptFile, 'Codex base prompt must match vendored prompt.md');
assertContains(CodexSystemPrompt::fullPrompt(), '## `apply_patch`', 'full prompt should include apply_patch instructions');
assertTrue(
    CodexSystemPrompt::promptFor('gpt-5.1-codex-max') === CodexSystemPrompt::gpt51CodexMaxPrompt() . CodexSystemPrompt::applyPatchInstructions(),
    'gpt-5.1-codex-max model routing must match OmniKit behavior',
);
assertTrue(
    CodexSystemPrompt::promptFor('gpt-5.2') === CodexSystemPrompt::gpt52Prompt() . CodexSystemPrompt::applyPatchInstructions(),
    'gpt-5.2 model routing must match OmniKit behavior',
);

$openAI = new OpenAIProfile(model: 'gpt-5.2');
$openPrompt = $openAI->buildSystemPrompt(
    environment: new FakeExecutionEnvironment('/tmp/workdir'),
    projectDocs: '# Project Documentation\nKeep tests green',
    userInstructions: 'Never force push',
    gitContext: null,
);
assertContains($openPrompt, '# Environment Context', 'OpenAI prompt must include environment block');
assertContains($openPrompt, 'Working directory: /tmp/workdir', 'OpenAI prompt must include working directory');
assertContains($openPrompt, '# User Instructions', 'OpenAI prompt must include user instructions block');

$gemini = new GeminiProfile(interactiveMode: false);
$geminiPrompt = $gemini->buildSystemPrompt(
    environment: new FakeExecutionEnvironment('/tmp/gemini'),
    projectDocs: null,
    userInstructions: null,
    gitContext: null,
);
assertContains($geminiPrompt, 'autonomous CLI agent', 'Gemini prompt should reflect non-interactive mode');
assertContains($geminiPrompt, '<env>', 'Gemini prompt must include env block');
assertContains($geminiPrompt, 'GEMINI.md', 'Gemini prompt should reference GEMINI.md rules');

$anthropic = new AnthropicProfile();
$anthPrompt = $anthropic->buildSystemPrompt(
    environment: new FakeExecutionEnvironment('/tmp/anthropic'),
    projectDocs: 'Team docs',
    userInstructions: 'Prefer immutable patterns',
    gitContext: new GitContext(branch: 'feature/x', modifiedFileCount: 2, recentCommits: 'abc123 init'),
);
assertContains($anthPrompt, '<env>', 'Anthropic prompt must include env block');
assertContains($anthPrompt, 'Assistant knowledge cutoff is May 2025.', 'Anthropic prompt must include cutoff note');
assertContains($anthPrompt, 'Current branch: feature/x', 'Anthropic prompt must include git branch');
assertContains($anthPrompt, '# Project Documentation', 'Anthropic prompt must include project docs section');
assertContains($anthPrompt, '# User Instructions', 'Anthropic prompt must include user instructions section');

$claudeToolDescriptions = ClaudeSystemPrompt::buildToolDescriptions(['Task', 'Bash']);
assertContains($claudeToolDescriptions, '# Tool: Task', 'Claude tool descriptions should include Task when allowed');
assertContains($claudeToolDescriptions, '# Tool: Bash', 'Claude tool descriptions should include Bash when allowed');
assertTrue(!str_contains($claudeToolDescriptions, '# Tool: Glob'), 'Claude tool descriptions should exclude disallowed tools');

$killShellFallback = ClaudeSystemPrompt::buildToolDescriptions(['KillShell']);
assertContains($killShellFallback, '# Tool: TaskStop', 'KillShell should map to TaskStop description');

$claudePrompt = ClaudeSystemPrompt::buildPrompt(
    environment: new ClaudePromptEnvironment('/tmp/claude', new ClaudePromptGitInfo('main', true, 'a1b2c3 Fix x')),
    enableTodos: false,
    modelName: 'Claude Sonnet 4.6',
    modelId: 'claude-sonnet-4-6',
    availableSkills: [],
    allowedTools: ['Task', 'Bash'],
);
assertContains($claudePrompt, 'You are Claude Code', 'Claude base prompt should be present');
assertContains($claudePrompt, 'Current branch: main', 'Claude prompt should include git section branch');

$tmp = makeTempDir();
$work = $tmp . '/workspace';
mkdir($work, 0777, true);
mkdir($tmp . '/.codex', 0777, true);
file_put_contents($tmp . '/AGENTS.md', 'root agents');
file_put_contents($tmp . '/CLAUDE.md', 'root claude');
file_put_contents($tmp . '/GEMINI.md', 'root gemini');
file_put_contents($tmp . '/.codex/instructions.md', 'root codex');
file_put_contents($work . '/AGENTS.md', 'work agents');
file_put_contents($work . '/GEMINI.md', 'work gemini');

$docEnv = new FakeExecutionEnvironment($work, [
    'git rev-parse --show-toplevel' => new ExecResult(0, $tmp . "\n", ''),
]);

$openDocs = ProjectDocDiscovery::discover('openai', $docEnv);
assertTrue(is_string($openDocs), 'OpenAI docs should be discovered');
assertContains($openDocs, '# AGENTS.md', 'OpenAI docs should include AGENTS');
assertContains($openDocs, '# .codex/instructions.md', 'OpenAI docs should include codex instructions');
assertTrue(!str_contains($openDocs, '# CLAUDE.md'), 'OpenAI docs should exclude CLAUDE.md');

$anthDocs = ProjectDocDiscovery::discover('anthropic', $docEnv);
assertTrue(is_string($anthDocs), 'Anthropic docs should be discovered');
assertContains($anthDocs, '# CLAUDE.md', 'Anthropic docs should include CLAUDE.md');
assertTrue(!str_contains($anthDocs, '# .codex/instructions.md'), 'Anthropic docs should exclude codex instructions');

$gemDocs = ProjectDocDiscovery::discover('gemini', $docEnv);
assertTrue(is_string($gemDocs), 'Gemini docs should be discovered');
assertContains($gemDocs, '# GEMINI.md', 'Gemini docs should include GEMINI.md');
assertTrue(!str_contains($gemDocs, '# CLAUDE.md'), 'Gemini docs should exclude CLAUDE.md');

$long = str_repeat('a', 33000);
file_put_contents($work . '/AGENTS.md', $long);
$truncated = ProjectDocDiscovery::discover('openai', new FakeExecutionEnvironment($work));
assertTrue(is_string($truncated), 'truncated docs should still return string');
assertContains($truncated, '[Project instructions truncated at 32KB]', 'truncation marker must be included');

$sessionEnv = new FakeExecutionEnvironment($work, [
    'git rev-parse --show-toplevel' => new ExecResult(0, $tmp . "\n", ''),
    'git rev-parse --abbrev-ref HEAD' => new ExecResult(0, "feature/session\n", ''),
    'git status --porcelain | wc -l' => new ExecResult(0, "3\n", ''),
    'git log --oneline -5' => new ExecResult(0, "abc one\ndef two\n", ''),
]);
$session = new Session(new OpenAIProfile(), $sessionEnv, 'Do not amend commits');
$sessionPrompt = $session->buildSystemPrompt();
assertContains($sessionPrompt, '# User Instructions', 'Session should append user instructions to system prompt');
assertContains($sessionPrompt, '# Environment Context', 'Session should include provider environment context');

rrmdir($tmp);

echo "Prompt system tests passed\n";
