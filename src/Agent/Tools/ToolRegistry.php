<?php

declare(strict_types=1);

namespace Attractor\Agent\Tools;

final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return list<Tool> */
    public function all(): array
    {
        return array_values($this->tools);
    }
}
