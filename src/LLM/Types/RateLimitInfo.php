<?php

declare(strict_types=1);

namespace Attractor\LLM\Types;

final class RateLimitInfo
{
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
        public readonly ?int $resetAtUnix = null,
    ) {
    }
}
