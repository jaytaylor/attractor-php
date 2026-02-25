<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

final class BufferedObserver implements Observer
{
    /** @var list<PipelineEvent> */
    private array $events = [];

    public function onEvent(PipelineEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<PipelineEvent> */
    public function all(): array
    {
        return $this->events;
    }
}
