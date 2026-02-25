<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Runtime;

final class Outcome
{
    /**
     * @param array<string, mixed> $contextUpdates
     * @param list<string> $suggestedNodeIds
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly ?string $preferredLabel = null,
        public readonly array $contextUpdates = [],
        public readonly array $suggestedNodeIds = [],
        public readonly int $score = 0,
    ) {
    }

    public static function success(string $message = '', ?string $preferredLabel = null, array $updates = []): self
    {
        return new self('SUCCESS', $message, $preferredLabel, $updates);
    }

    public static function fail(string $message = '', ?string $preferredLabel = null, array $updates = []): self
    {
        return new self('FAIL', $message, $preferredLabel, $updates);
    }

    public static function retry(string $message = '', ?string $preferredLabel = null, array $updates = []): self
    {
        return new self('RETRY', $message, $preferredLabel, $updates);
    }

    public static function waiting(string $message = '', ?string $preferredLabel = null, array $updates = []): self
    {
        return new self('WAITING', $message, $preferredLabel, $updates);
    }
}
