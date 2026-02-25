<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class Role
{
    public const SYSTEM = 'system';
    public const USER = 'user';
    public const ASSISTANT = 'assistant';
    public const TOOL = 'tool';
    public const DEVELOPER = 'developer';

    public static function isValid(string $role): bool
    {
        return in_array($role, [
            self::SYSTEM,
            self::USER,
            self::ASSISTANT,
            self::TOOL,
            self::DEVELOPER,
        ], true);
    }
}
