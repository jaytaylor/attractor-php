<?php

declare(strict_types=1);

namespace Attractor\LLM\Tools;

final class ToolDefinition
{
    /**
     * @param array<string, mixed> $parametersSchema
     * @param null|callable(array<string, mixed>): array<string, mixed> $execute
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parametersSchema,
        public readonly ?\Closure $execute = null,
    ) {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('invalid tool name');
        }
        if (strlen($name) > 64) {
            throw new \InvalidArgumentException('tool name too long');
        }
        if (($parametersSchema['type'] ?? null) !== 'object') {
            throw new \InvalidArgumentException('tool schema root type must be object');
        }
    }

    public function isActive(): bool
    {
        return $this->execute !== null;
    }
}
