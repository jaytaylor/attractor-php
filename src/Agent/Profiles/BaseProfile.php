<?php

declare(strict_types=1);

namespace Attractor\Agent\Profiles;

use Attractor\Agent\ExecutionEnvironment;
use Attractor\Agent\ProjectDocs;
use Attractor\Agent\ProviderProfile;
use Attractor\Agent\Tools\ToolRegistry;

abstract class BaseProfile implements ProviderProfile
{
    public function __construct(
        protected readonly string $model,
        protected readonly ToolRegistry $registry,
        protected readonly ?array $providerOptions = null,
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    public function tools(): array
    {
        return array_map(
            static fn (\Attractor\Agent\Tools\Tool $tool): \Attractor\LLM\Tools\ToolDefinition => new \Attractor\LLM\Tools\ToolDefinition(
                name: $tool->name,
                description: $tool->description,
                parametersSchema: $tool->parametersSchema,
            ),
            $this->registry->all(),
        );
    }

    public function providerOptions(): ?array
    {
        return $this->providerOptions;
    }

    public function toolRegistry(): ToolRegistry
    {
        return $this->registry;
    }

    public function buildSystemPrompt(ExecutionEnvironment $env, ProjectDocs $docs): string
    {
        $date = date('Y-m-d');
        $tools = implode(', ', array_map(static fn ($tool): string => $tool->name, $this->registry->all()));

        return implode("\n\n", array_filter([
            $this->baseInstructions(),
            "Environment:\n- cwd: " . $env->workingDirectory() . "\n- date: {$date}",
            "Tools: {$tools}",
            $docs->asPromptBlock(),
        ]));
    }

    public function contextWindowSize(): int
    {
        return 200_000;
    }

    abstract protected function baseInstructions(): string;
}
