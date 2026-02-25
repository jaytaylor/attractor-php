<?php

declare(strict_types=1);

namespace Attractor\LLM\Tools;

final class ToolResult
{
    /** @param array<string, mixed> $result */
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $name,
        public readonly array $result,
        public readonly bool $isError = false,
    ) {
    }
}
