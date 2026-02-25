<?php

declare(strict_types=1);

namespace Attractor\Agent;

interface EventEmitter
{
    public function emit(SessionEvent $event): void;
}
