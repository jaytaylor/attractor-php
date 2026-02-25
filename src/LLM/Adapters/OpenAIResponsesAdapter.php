<?php

declare(strict_types=1);

namespace Attractor\LLM\Adapters;

use Attractor\LLM\Http\HttpRequest;
use Attractor\LLM\Http\HttpResponse;
use Attractor\LLM\Tools\ToolCall;
use Attractor\LLM\Types\ContentKind;
use Attractor\LLM\Types\ContentPart;
use Attractor\LLM\Types\Message;
use Attractor\LLM\Types\RateLimitInfo;
use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Response;
use Attractor\LLM\Types\Role;
use Attractor\LLM\Types\StreamEvent;
use Attractor\LLM\Types\StreamEventType;
use Attractor\LLM\Types\Usage;

final class OpenAIResponsesAdapter extends BaseHttpAdapter
{
    public function name(): string
    {
        return 'openai';
    }

    protected function buildCompleteRequest(Request $request): HttpRequest
    {
        $payload = [
            'model' => $request->model,
            'input' => array_map(fn (Message $m): array => [
                'role' => $m->role,
                'content' => array_map(fn (ContentPart $p): array => $this->toOpenAIContent($p), $m->content),
            ], $request->resolvedMessages()),
            'stream' => false,
        ];

        if ($request->tools !== []) {
            $payload['tools'] = array_map(fn ($tool): array => [
                'type' => 'function',
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->parametersSchema,
            ], $request->tools);
        }

        return new HttpRequest(
            method: 'POST',
            url: rtrim($this->baseUrl, '/') . '/responses',
            headers: [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    protected function buildStreamRequest(Request $request): HttpRequest
    {
        $request = new Request(
            provider: $request->provider,
            model: $request->model,
            messages: $request->messages,
            prompt: $request->prompt,
            tools: $request->tools,
            toolChoice: $request->toolChoice,
            providerOptions: $request->providerOptions,
            maxToolRounds: $request->maxToolRounds,
            maxRetries: $request->maxRetries,
            timeoutMs: $request->timeoutMs,
        );

        $http = $this->buildCompleteRequest($request);
        $payload = json_decode($http->body, true, 512, JSON_THROW_ON_ERROR);
        $payload['stream'] = true;

        return new HttpRequest($http->method, $http->url, $http->headers, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    protected function parseCompleteResponse(Request $request, HttpResponse $response): Response
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $text = '';
        $toolCalls = [];

        foreach (($data['output'] ?? []) as $entry) {
            if (($entry['type'] ?? '') === 'message') {
                foreach (($entry['content'] ?? []) as $part) {
                    if (($part['type'] ?? '') === 'output_text') {
                        $text .= (string) ($part['text'] ?? '');
                    }
                }
            }
            if (($entry['type'] ?? '') === 'function_call') {
                $toolCalls[] = new ToolCall(
                    id: (string) ($entry['call_id'] ?? $entry['id'] ?? uniqid('call_', true)),
                    name: (string) ($entry['name'] ?? ''),
                    arguments: json_decode((string) ($entry['arguments'] ?? '{}'), true) ?: [],
                );
            }
        }

        $usageRaw = $data['usage'] ?? [];
        $usage = new Usage(
            inputTokens: (int) ($usageRaw['input_tokens'] ?? 0),
            outputTokens: (int) ($usageRaw['output_tokens'] ?? 0),
            reasoningTokens: (int) (($usageRaw['output_tokens_details']['reasoning_tokens'] ?? 0)),
            cacheReadTokens: (int) (($usageRaw['input_tokens_details']['cached_tokens'] ?? 0)),
            cacheWriteTokens: 0,
        );

        $rate = new RateLimitInfo(
            limit: is_numeric($response->header('x-ratelimit-limit-requests')) ? (int) $response->header('x-ratelimit-limit-requests') : null,
            remaining: is_numeric($response->header('x-ratelimit-remaining-requests')) ? (int) $response->header('x-ratelimit-remaining-requests') : null,
            resetAtUnix: null,
        );

        return new Response(
            provider: 'openai',
            model: (string) ($data['model'] ?? $request->model),
            messages: [new Message(Role::ASSISTANT, [ContentPart::text($text)])],
            usage: $usage,
            finishReason: (string) ($data['status'] ?? 'stop'),
            toolCalls: $toolCalls,
            rateLimitInfo: $rate,
        );
    }

    protected function parseStreamResponse(Request $request, HttpResponse $response): \Traversable
    {
        yield new StreamEvent(StreamEventType::STREAM_START, ['provider' => 'openai']);
        $lines = preg_split('/\R/', $response->body) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $payload = trim(substr($line, 5));
                if ($payload === '[DONE]') {
                    break;
                }
                $data = json_decode($payload, true);
                if (!is_array($data)) {
                    yield new StreamEvent(StreamEventType::PROVIDER_EVENT, ['raw' => $payload]);
                    continue;
                }

                $type = (string) ($data['type'] ?? '');
                if ($type === 'response.output_text.delta') {
                    yield new StreamEvent(StreamEventType::TEXT_DELTA, ['text' => (string) ($data['delta'] ?? '')]);
                } else {
                    yield new StreamEvent(StreamEventType::PROVIDER_EVENT, ['raw' => $data]);
                }
            }
        }

        yield new StreamEvent(StreamEventType::FINISH, ['usage' => new Usage()]);
    }

    /** @return array<string, mixed> */
    private function toOpenAIContent(ContentPart $part): array
    {
        if ($part->kind === ContentKind::TEXT) {
            return ['type' => 'input_text', 'text' => $part->textValue()];
        }
        if ($part->kind === ContentKind::IMAGE) {
            return [
                'type' => 'input_image',
                'image_url' => $part->data['url'] ?? $part->data['data'] ?? $part->data['path'] ?? '',
            ];
        }

        return ['type' => $part->kind, 'data' => $part->data];
    }
}
