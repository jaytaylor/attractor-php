<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Model;

final class Node
{
    /** @param array<string, string> $attrs */
    public function __construct(
        public readonly string $id,
        public array $attrs = [],
    ) {
    }

    public function attr(string $key, ?string $default = null): ?string
    {
        return $this->attrs[$key] ?? $default;
    }

    public function shape(): string
    {
        return $this->attrs['shape'] ?? 'box';
    }

    public function type(): string
    {
        $shape = $this->shape();
        return match ($shape) {
            'Mdiamond' => 'start',
            'Msquare' => 'exit',
            'diamond' => 'conditional',
            'parallelogram' => 'tool',
            default => 'codergen',
        };
    }
}
