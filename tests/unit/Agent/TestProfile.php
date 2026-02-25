<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\Agent;

use Attractor\Agent\ExecutionEnvironment;
use Attractor\Agent\ProjectDocs;
use Attractor\Agent\ProviderProfile;
use Attractor\Agent\Tools\ToolRegistry;

final class TestProfile implements ProviderProfile
{
    /** @param list<\Attractor\LLM\Tools\ToolDefinition> $llmTools */
    public function __construct(
        private readonly string $id,
        private readonly string $model,
        private readonly array $llmTools,
        private readonly ToolRegistry $registry,
        private readonly bool $parallel = true,
        private readonly ?array $providerOptions = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function tools(): array
    {
        return $this->llmTools;
    }

    public function buildSystemPrompt(ExecutionEnvironment $env, ProjectDocs $docs): string
    {
        return "test system prompt\n" . $docs->asPromptBlock();
    }

    public function providerOptions(): ?array
    {
        return $this->providerOptions;
    }

    public function toolRegistry(): ToolRegistry
    {
        return $this->registry;
    }

    public function supportsParallelToolCalls(): bool
    {
        return $this->parallel;
    }

    public function contextWindowSize(): int
    {
        return 1024;
    }
}
