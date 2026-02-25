<?php

declare(strict_types=1);

namespace Attractor\LLM;

use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Response;

interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
