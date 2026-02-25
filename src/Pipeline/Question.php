<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

final class Question
{
    /** @param list<string> $options */
    public function __construct(
        public readonly string $type,
        public readonly string $prompt,
        public readonly array $options = [],
    ) {
    }
}
