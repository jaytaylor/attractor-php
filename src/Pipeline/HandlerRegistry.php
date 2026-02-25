<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

final class HandlerRegistry
{
    /** @var array<string, Handler> */
    private array $handlers = [];

    public function register(string $type, Handler $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function get(string $type): ?Handler
    {
        return $this->handlers[$type] ?? null;
    }
}
