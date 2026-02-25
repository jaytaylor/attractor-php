<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

use Attractor\LLM\Tools\ToolCall;

final class Response
{
    /**
     * @param list<Message> $messages
     * @param list<ToolCall> $toolCalls
     * @param list<WarningEntry> $warnings
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array $messages,
        public readonly Usage $usage = new Usage(),
        public readonly string $finishReason = 'stop',
        public readonly array $toolCalls = [],
        public readonly array $warnings = [],
        public readonly ?RateLimitInfo $rateLimitInfo = null,
    ) {
    }

    public function text(): string
    {
        foreach ($this->messages as $message) {
            if ($message->role === Role::ASSISTANT) {
                return $message->text();
            }
        }

        return '';
    }

    /** @return list<ContentPart> */
    public function reasoning(): array
    {
        $parts = [];
        foreach ($this->messages as $message) {
            if ($message->role !== Role::ASSISTANT) {
                continue;
            }
            foreach ($message->content as $part) {
                if ($part->kind === ContentKind::THINKING) {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }
}
