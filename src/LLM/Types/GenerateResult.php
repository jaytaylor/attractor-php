<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class GenerateResult
{
    /** @param list<StepResult> $steps */
    public function __construct(
        public readonly string $text,
        public readonly Response $response,
        public readonly array $steps,
    ) {
    }
}
