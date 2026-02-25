<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

use Attractor\LLM\Tools\ToolCall;
use Attractor\LLM\Tools\ToolResult;

final class StepResult
{
    /**
     * @param list<ToolCall> $toolCalls
     * @param list<ToolResult> $toolResults
     */
    public function __construct(
        public readonly Response $response,
        public readonly array $toolCalls = [],
        public readonly array $toolResults = [],
    ) {
    }
}
