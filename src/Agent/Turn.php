<?php

declare(strict_types=1);

namespace Attractor\Agent;

final class Turn
{
    public function __construct(
        public readonly string $kind,
        public readonly string $role,
        public readonly string $text,
    ) {
    }
}
