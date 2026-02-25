<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Model;

final class Edge
{
    /** @param array<string, string> $attrs */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public array $attrs = [],
    ) {
    }

    public function label(): string
    {
        return (string) ($this->attrs['label'] ?? '');
    }

    public function condition(): string
    {
        return (string) ($this->attrs['condition'] ?? '');
    }

    public function weight(): int
    {
        return isset($this->attrs['weight']) ? (int) $this->attrs['weight'] : 0;
    }
}
