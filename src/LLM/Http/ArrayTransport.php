<?php

declare(strict_types=1);

namespace Attractor\LLM\Http;

final class ArrayTransport implements HttpTransport
{
    /** @var list<HttpRequest> */
    private array $requests = [];

    /** @var list<HttpResponse> */
    private array $responses;

    /** @param list<HttpResponse> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if ($this->responses === []) {
            return new HttpResponse(500, body: '{"error":"no queued response"}');
        }

        return array_shift($this->responses);
    }

    /** @return list<HttpRequest> */
    public function requests(): array
    {
        return $this->requests;
    }
}
