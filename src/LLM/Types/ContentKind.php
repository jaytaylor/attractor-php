<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class ContentKind
{
    public const TEXT = 'text';
    public const IMAGE = 'image';
    public const AUDIO = 'audio';
    public const DOCUMENT = 'document';
    public const TOOL_CALL = 'tool_call';
    public const TOOL_RESULT = 'tool_result';
    public const THINKING = 'thinking';
    public const PROVIDER_RAW = 'provider_raw';
}
