<?php

declare(strict_types=1);

namespace Attractor\LLM\Errors;

class ProviderError extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly bool $retryable,
        private readonly ?int $retryAfterSeconds = null,
        private readonly ?string $provider = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }
}
