<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class Message
{
    /** @param list<ContentPart> $content */
    public function __construct(
        public readonly string $role,
        public readonly array $content,
    ) {
        if (!Role::isValid($role)) {
            throw new \InvalidArgumentException("invalid role: {$role}");
        }
    }

    public static function fromText(string $role, string $text): self
    {
        return new self($role, [ContentPart::text($text)]);
    }

    public function text(): string
    {
        $chunks = [];
        foreach ($this->content as $part) {
            if ($part->kind === ContentKind::TEXT) {
                $chunks[] = $part->textValue();
            }
        }

        return implode('', $chunks);
    }
}
