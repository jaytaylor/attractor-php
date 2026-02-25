<?php

declare(strict_types=1);

namespace Attractor\Agent\Subagents;

use Attractor\Agent\Session;

final class SubagentManager
{
    /** @var array<string, Session> */
    private array $agents = [];

    public function __construct(private readonly int $maxDepth = 1)
    {
    }

    public function canSpawn(int $depth): bool
    {
        return $depth < $this->maxDepth;
    }

    public function register(string $id, Session $session): void
    {
        $this->agents[$id] = $session;
    }

    public function get(string $id): ?Session
    {
        return $this->agents[$id] ?? null;
    }

    public function close(string $id): void
    {
        if (!isset($this->agents[$id])) {
            return;
        }

        $this->agents[$id]->close();
        unset($this->agents[$id]);
    }
}
