<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class StreamEventType
{
    public const STREAM_START = 'stream_start';
    public const TEXT_START = 'text_start';
    public const TEXT_DELTA = 'text_delta';
    public const TEXT_END = 'text_end';
    public const TOOL_CALL = 'tool_call';
    public const FINISH = 'finish';
    public const PROVIDER_EVENT = 'provider_event';
}
