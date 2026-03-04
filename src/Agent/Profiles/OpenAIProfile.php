<?php

declare(strict_types=1);

namespace App\Agent\Profiles;

use App\Agent\Exec\ExecutionEnvironment;
use App\Agent\Prompts\CodexSystemPrompt;

final class OpenAIProfile implements ProviderProfile
{
    /**
     * @param list<string> $toolNames
     */
    public function __construct(
        private readonly string $model = 'gpt-5.2',
        private readonly array $toolNames = ['exec_command', 'write_stdin', 'read_file', 'grep', 'list_dir', 'update_plan', 'view_image', 'apply_patch'],
    ) {
    }

    public function id(): string
    {
        return 'openai';
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
        $basePrompt = CodexSystemPrompt::promptFor($this->model);
        $workingDir = $environment->workingDirectory();

        $envContext = "\n\n# Environment Context\n\n"
            . "- Working directory: {$workingDir}\n"
            . "- When using shell tools, use \".\" or \"{$workingDir}\" for the workdir parameter, not \"/workspace\"\n"
            . '- Platform: ' . $environment->platform() . ' ' . $environment->osVersion() . "\n";

        $sections = [$basePrompt . $envContext];

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
        return 200_000;
    }
}
