<?php

declare(strict_types=1);

namespace Attractor\Agent\Tools;

use Attractor\Agent\ExecutionEnvironment;

final class Tool
{
    /**
     * @param array<string, mixed> $parametersSchema
     * @param callable(array<string, mixed>, ExecutionEnvironment): array<string, mixed> $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parametersSchema,
        public readonly \Closure $handler,
    ) {
    }

    /** @param array<string, mixed> $arguments */
    public function execute(array $arguments, ExecutionEnvironment $environment): array
    {
        return ($this->handler)($arguments, $environment);
    }

    public function toLlmToolDefinition(): \Attractor\LLM\Tools\ToolDefinition
    {
        return new \Attractor\LLM\Tools\ToolDefinition(
            name: $this->name,
            description: $this->description,
            parametersSchema: $this->parametersSchema,
            execute: fn (array $args): array => ($this->handler)($args, new \Attractor\Agent\Exec\LocalExecutionEnvironment(getcwd() ?: '.')),
        );
    }
}
