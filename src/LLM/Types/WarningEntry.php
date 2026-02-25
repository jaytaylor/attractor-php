<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class WarningEntry
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
    }
}
