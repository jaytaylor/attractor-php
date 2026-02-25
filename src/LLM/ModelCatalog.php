<?php

declare(strict_types=1);

namespace Attractor\LLM;

final class ModelCatalog
{
    /** @var array<string, array<string, mixed>> */
    private array $models;

    public function __construct(?array $models = null)
    {
        $this->models = $models ?? [
            'openai:gpt-5.2' => ['provider' => 'openai', 'name' => 'gpt-5.2'],
            'anthropic:claude-sonnet-4-5' => ['provider' => 'anthropic', 'name' => 'claude-sonnet-4-5'],
            'gemini:gemini-2.0-flash' => ['provider' => 'gemini', 'name' => 'gemini-2.0-flash'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function getModelInfo(string $id): ?array
    {
        return $this->models[$id] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public function listModels(): array
    {
        return $this->models;
    }
}
