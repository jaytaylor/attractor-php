<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

interface Observer
{
    public function onEvent(PipelineEvent $event): void;
}
