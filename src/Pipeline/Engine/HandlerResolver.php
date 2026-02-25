<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Engine;

use Attractor\Pipeline\Handler;
use Attractor\Pipeline\HandlerRegistry;
use Attractor\Pipeline\Model\Node;

final class HandlerResolver
{
    public function __construct(private readonly HandlerRegistry $registry)
    {
    }

    public function resolve(Node $node): Handler
    {
        $type = (string) ($node->attr('type') ?? $node->type());
        $handler = $this->registry->get($type);
        if ($handler === null) {
            throw new \RuntimeException("handler not registered: {$type}");
        }

        return $handler;
    }
}
