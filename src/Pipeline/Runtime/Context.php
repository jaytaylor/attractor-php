<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Runtime;

final class Context
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = ''): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    /** @param array<string, mixed> $updates */
    public function merge(array $updates): void
    {
        foreach ($updates as $k => $v) {
            $this->values[$k] = $v;
        }
    }
}
