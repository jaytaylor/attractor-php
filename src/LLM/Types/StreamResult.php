<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class StreamResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?Usage $usage = null,
    ) {
    }
}
