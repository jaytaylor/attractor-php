<?php

declare(strict_types=1);

namespace App\Agent\Profiles;

use App\Agent\Exec\ExecutionEnvironment;
use App\Agent\Prompts\ClaudePromptEnvironment;
use App\Agent\Prompts\ClaudePromptGitInfo;
use App\Agent\Prompts\ClaudePromptSkill;
use App\Agent\Prompts\ClaudeSystemPrompt;

final class AnthropicProfile implements ProviderProfile
{
    /**
     * @var list<string>
     */
    private array $toolNames;

    public function __construct(
        private readonly string $model = 'claude-haiku-4-5',
        bool $enableTodos = true,
        bool $enableInteractiveTools = true,
    ) {
        $tools = [
            'Read', 'Write', 'Edit', 'Glob', 'Grep', 'Bash', 'WebFetch', 'WebSearch', 'ViewImage',
        ];

        if ($enableInteractiveTools) {
            $tools = array_merge($tools, [
                'NotebookEdit', 'TaskStop', 'TaskOutput', 'Task', 'TaskCreate', 'TaskGet', 'TaskList',
                'TaskUpdate', 'TeamCreate', 'TeamDelete', 'SendMessage', 'ToolSearch', 'AskUserQuestion',
                'EnterPlanMode', 'ExitPlanMode', 'Skill',
            ]);
            if ($enableTodos) {
                $tools[] = 'TodoWrite';
            }
        }

        $this->toolNames = $tools;
    }

    public function id(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function toolNames(): array
    {
        return $this->toolNames;
    }

    public function buildSystemPrompt(
        ExecutionEnvironment $environment,
        ?string $projectDocs,
        ?string $userInstructions,
        ?GitContext $gitContext,
    ): string {
        $workingDirectory = $environment->workingDirectory();
        $skills = $this->loadSkills($workingDirectory);
        $allowedTools = $this->toolNames();
        $todosEnabled = in_array('TodoWrite', $allowedTools, true);

        $promptGitInfo = null;
        if ($gitContext !== null) {
            $promptGitInfo = new ClaudePromptGitInfo(
                branch: $gitContext->branch,
                hasUncommittedChanges: $gitContext->modifiedFileCount > 0,
                recentCommits: $gitContext->recentCommits,
            );
        }

        $promptEnvironment = new ClaudePromptEnvironment(
            workingDirectory: $workingDirectory,
            gitInfo: $promptGitInfo,
        );

        $basePrompt = ClaudeSystemPrompt::buildPrompt(
            environment: $promptEnvironment,
            enableTodos: $todosEnabled,
            modelName: $this->modelDisplayName(),
            modelId: $this->model,
            availableSkills: $skills,
            allowedTools: $allowedTools,
        );

        $sections = [$basePrompt];

        if ($projectDocs !== null && $projectDocs !== '') {
            $sections[] = "# Project Documentation\n{$projectDocs}\n";
        }

        if ($userInstructions !== null && $userInstructions !== '') {
            $sections[] = "# User Instructions\n{$userInstructions}\n";
        }

        return implode("\n\n", $sections);
    }

    public function supportsReasoning(): bool
    {
        return true;
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsParallelToolCalls(): bool
    {
        return true;
    }

    public function contextWindowSize(): int
    {
        return str_contains($this->model, '[1m]') ? 1_000_000 : 200_000;
    }

    /**
     * @return list<ClaudePromptSkill>
     */
    private function loadSkills(string $workingDirectory): array
    {
        $commandsDir = rtrim($workingDirectory, '/') . '/.claude/commands';
        if (!is_dir($commandsDir)) {
            return [];
        }

        $skills = [];
        $entries = scandir($commandsDir);
        if (!is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                continue;
            }

            $path = $commandsDir . '/' . $entry;
            $skill = $this->parseSkillFile($path);
            if ($skill !== null) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    private function parseSkillFile(string $path): ?ClaudePromptSkill
    {
        $content = @file_get_contents($path);
        if (!is_string($content)) {
            return null;
        }

        $name = pathinfo($path, PATHINFO_FILENAME);
        $description = 'No description';
        $body = $content;

        if (str_starts_with($content, '---')) {
            $parts = explode('---', $content);
            if (count($parts) >= 3) {
                $yamlContent = $parts[1];
                $body = implode('---', array_slice($parts, 2));

                foreach (preg_split('/\R/', $yamlContent) ?: [] as $line) {
                    $trimmed = trim($line);
                    if (str_starts_with($trimmed, 'description:')) {
                        $description = trim(substr($trimmed, strlen('description:')));
                        break;
                    }
                }
            }
        }

        return new ClaudePromptSkill(
            name: $name,
            description: $description,
            content: trim($body),
        );
    }

    private function modelDisplayName(): string
    {
        if (str_contains($this->model, 'opus')) {
            return 'Claude Opus 4.6';
        }
        if (str_contains($this->model, 'sonnet-4-6')) {
            return 'Claude Sonnet 4.6';
        }
        if (str_contains($this->model, 'sonnet')) {
            return 'Claude Sonnet 4.5';
        }
        if (str_contains($this->model, 'haiku')) {
            return 'Claude Haiku 4.5';
        }
        return 'Claude';
    }
}
