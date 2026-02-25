<?php

declare(strict_types=1);

namespace Attractor\LLM;

use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Response;

interface ProviderAdapter
{
    public function name(): string;

    public function complete(Request $request): Response;

    /** @return \Traversable<int, \Attractor\LLM\Types\StreamEvent> */
    public function stream(Request $request): \Traversable;
}
