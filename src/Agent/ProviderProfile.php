<?php

declare(strict_types=1);

namespace Attractor\Agent;

use Attractor\Agent\Tools\ToolRegistry;

interface ProviderProfile
{
    public function id(): string;

    public function model(): string;

    /** @return list<\Attractor\LLM\Tools\ToolDefinition> */
    public function tools(): array;

    public function buildSystemPrompt(ExecutionEnvironment $env, ProjectDocs $docs): string;

    /** @return array<string, mixed>|null */
    public function providerOptions(): ?array;

    public function toolRegistry(): ToolRegistry;

    public function supportsParallelToolCalls(): bool;

    public function contextWindowSize(): int;
}
