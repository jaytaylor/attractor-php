<?php

declare(strict_types=1);

namespace Attractor\LLM\Tools;

final class ToolChoice
{
    public const AUTO = 'auto';
    public const NONE = 'none';
    public const REQUIRED = 'required';

    public function __construct(
        public readonly string $mode = self::AUTO,
        public readonly ?string $name = null,
    ) {
    }
}
