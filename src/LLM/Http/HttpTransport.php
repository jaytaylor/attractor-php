<?php

declare(strict_types=1);

namespace Attractor\LLM\Http;

interface HttpTransport
{
    public function send(HttpRequest $request): HttpResponse;
}
