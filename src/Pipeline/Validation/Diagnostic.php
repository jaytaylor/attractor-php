<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Validation;

final class Diagnostic
{
    public function __construct(
        public readonly string $rule,
        public readonly string $severity,
        public readonly string $targetId,
        public readonly string $message,
    ) {
    }
}
