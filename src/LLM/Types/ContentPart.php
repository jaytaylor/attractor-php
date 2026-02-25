<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class ContentPart
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $kind,
        public readonly array $data,
    ) {
    }

    public static function text(string $text): self
    {
        return new self(ContentKind::TEXT, ['text' => $text]);
    }

    public function textValue(): string
    {
        return (string) ($this->data['text'] ?? '');
    }
}
