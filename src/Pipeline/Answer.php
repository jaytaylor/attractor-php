<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

final class Answer
{
    /** @param list<string> $selected */
    public function __construct(
        public readonly array $selected = [],
        public readonly ?string $text = null,
        public readonly bool $confirmed = false,
    ) {
    }
}
