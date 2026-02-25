<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class GenerateObjectResult
{
    /** @param array<string, mixed> $object */
    public function __construct(
        public readonly array $object,
        public readonly GenerateResult $result,
    ) {
    }
}
