<?php

declare(strict_types=1);

namespace Attractor\LLM\Adapters;

use Attractor\LLM\Errors\AuthenticationError;
use Attractor\LLM\Errors\ProviderError;
use Attractor\LLM\Errors\RateLimitError;
use Attractor\LLM\Errors\ServerError;
use Attractor\LLM\Http\HttpRequest;
use Attractor\LLM\Http\HttpResponse;
use Attractor\LLM\Http\HttpTransport;
use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Response;
use Attractor\LLM\Types\StreamEvent;

abstract class BaseHttpAdapter implements \Attractor\LLM\ProviderAdapter
{
    public function __construct(
        protected readonly string $apiKey,
        protected readonly HttpTransport $transport,
        protected readonly string $baseUrl,
    ) {
    }

    final public function complete(Request $request): Response
    {
        $attempt = 0;
        $maxRetries = $request->maxRetries ?? 2;

        while (true) {
            $attempt++;
            $httpRequest = $this->buildCompleteRequest($request);
            $response = $this->transport->send($httpRequest);
            try {
                $this->throwForHttpError($response);
                return $this->parseCompleteResponse($request, $response);
            } catch (ProviderError $error) {
                if (!$error->retryable() || $attempt > $maxRetries) {
                    throw $error;
                }
            }
        }
    }

    final public function stream(Request $request): \Traversable
    {
        $httpRequest = $this->buildStreamRequest($request);
        $response = $this->transport->send($httpRequest);
        $this->throwForHttpError($response);

        foreach ($this->parseStreamResponse($request, $response) as $event) {
            yield $event;
        }
    }

    protected function throwForHttpError(HttpResponse $response): void
    {
        if ($response->statusCode < 400) {
            return;
        }

        $retryAfter = $response->header('Retry-After');
        $retryAfterSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;

        if (in_array($response->statusCode, [401, 403], true)) {
            throw new AuthenticationError('authentication failed', $response->statusCode, false, null, $this->name());
        }

        if ($response->statusCode === 429) {
            throw new RateLimitError('rate limited', 429, true, $retryAfterSeconds, $this->name());
        }

        if ($response->statusCode >= 500) {
            throw new ServerError('provider server error', $response->statusCode, true, $retryAfterSeconds, $this->name());
        }

        throw new ProviderError('provider request failed', $response->statusCode, false, $retryAfterSeconds, $this->name());
    }

    abstract protected function buildCompleteRequest(Request $request): HttpRequest;

    abstract protected function buildStreamRequest(Request $request): HttpRequest;

    abstract protected function parseCompleteResponse(Request $request, HttpResponse $response): Response;

    /** @return \Traversable<int, StreamEvent> */
    abstract protected function parseStreamResponse(Request $request, HttpResponse $response): \Traversable;
}
