<?php

declare(strict_types=1);

namespace App\Agent\Profiles;

use App\Agent\Exec\ExecutionEnvironment;
use App\Agent\Prompts\GeminiSystemPrompt;

final class GeminiProfile implements ProviderProfile
{
    /**
     * @var list<string>
     */
    private array $toolNames;

    public function __construct(
        private readonly string $model = 'gemini-3-flash-preview',
        private readonly bool $interactiveMode = true,
        bool $enableTodos = true,
        bool $enablePlanTools = true,
    ) {
        $tools = [
            'glob', 'read_file', 'write_file', 'replace', 'grep_search', 'list_directory', 'read_many_files',
            'run_shell_command', 'google_web_search', 'web_fetch', 'save_memory', 'get_internal_docs',
            'activate_skill', 'view_image', 'ask_user',
        ];

        if ($enableTodos) {
            $tools[] = 'write_todos';
        }

        if ($enablePlanTools) {
            $tools[] = 'enter_plan_mode';
            $tools[] = 'exit_plan_mode';
        }

        $this->toolNames = $tools;
    }

    public function id(): string
    {
        return 'gemini';
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
        $hasTodos = in_array('write_todos', $this->toolNames, true);
        $hasPlanTools = in_array('enter_plan_mode', $this->toolNames, true);

        $prompt = GeminiSystemPrompt::buildPrompt([
            'interactiveMode' => $this->interactiveMode,
            'enableWriteTodosTool' => $hasTodos,
            'enableEnterPlanModeTool' => $hasPlanTools,
            'isGitRepository' => $gitContext !== null,
            'sandbox' => GeminiSystemPrompt::SANDBOX_OUTSIDE,
        ]);

        $sections = [$prompt];
        $today = date('Y-m-d');
        $sections[] = "<env>\n"
            . 'Working directory: ' . $environment->workingDirectory() . "\n"
            . 'Platform: ' . $environment->platform() . "\n"
            . 'OS Version: ' . $environment->osVersion() . "\n"
            . "Today's date: {$today}\n"
            . 'Model: ' . $this->model . "\n"
            . '</env>';

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
        return 1_000_000;
    }
}
