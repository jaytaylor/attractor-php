<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

use Attractor\LLM\Tools\ToolChoice;
use Attractor\LLM\Tools\ToolDefinition;

final class Request
{
    /**
     * @param list<Message> $messages
     * @param list<ToolDefinition> $tools
     * @param array<string, mixed> $providerOptions
     */
    public function __construct(
        public readonly ?string $provider,
        public readonly string $model,
        public readonly array $messages = [],
        public readonly ?string $prompt = null,
        public readonly array $tools = [],
        public readonly ?ToolChoice $toolChoice = null,
        public readonly array $providerOptions = [],
        public readonly int $maxToolRounds = 4,
        public readonly ?int $maxRetries = 2,
        public readonly ?int $timeoutMs = null,
    ) {
        if ($prompt !== null && $messages !== []) {
            throw new \InvalidArgumentException('prompt and messages are mutually exclusive');
        }
    }

    public static function fromPrompt(string $model, string $prompt, ?string $provider = null): self
    {
        return new self(provider: $provider, model: $model, prompt: $prompt);
    }

    /** @return list<Message> */
    public function resolvedMessages(): array
    {
        if ($this->prompt !== null) {
            return [Message::fromText(Role::USER, $this->prompt)];
        }

        return $this->messages;
    }
}
