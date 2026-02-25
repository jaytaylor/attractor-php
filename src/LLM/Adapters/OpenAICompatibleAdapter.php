<?php

declare(strict_types=1);

namespace Attractor\LLM\Adapters;

use Attractor\LLM\Http\HttpRequest;
use Attractor\LLM\Http\HttpResponse;
use Attractor\LLM\Types\ContentPart;
use Attractor\LLM\Types\Message;
use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Response;
use Attractor\LLM\Types\Role;
use Attractor\LLM\Types\StreamEvent;
use Attractor\LLM\Types\StreamEventType;
use Attractor\LLM\Types\Usage;

final class OpenAICompatibleAdapter extends BaseHttpAdapter
{
    public function name(): string
    {
        return 'openai-compatible';
    }

    protected function buildCompleteRequest(Request $request): HttpRequest
    {
        $messages = array_map(fn (Message $m): array => [
            'role' => $m->role,
            'content' => $m->text(),
        ], $request->resolvedMessages());

        return new HttpRequest(
            method: 'POST',
            url: rtrim($this->baseUrl, '/') . '/chat/completions',
            headers: [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            body: json_encode([
                'model' => $request->model,
                'messages' => $messages,
                'stream' => false,
            ], JSON_THROW_ON_ERROR),
        );
    }

    protected function buildStreamRequest(Request $request): HttpRequest
    {
        $http = $this->buildCompleteRequest($request);
        $payload = json_decode($http->body, true, 512, JSON_THROW_ON_ERROR);
        $payload['stream'] = true;

        return new HttpRequest($http->method, $http->url, $http->headers, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    protected function parseCompleteResponse(Request $request, HttpResponse $response): Response
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $choice = $data['choices'][0] ?? [];
        $text = (string) ($choice['message']['content'] ?? '');

        $usageRaw = $data['usage'] ?? [];
        return new Response(
            provider: $request->provider ?? 'openai-compatible',
            model: (string) ($data['model'] ?? $request->model),
            messages: [new Message(Role::ASSISTANT, [ContentPart::text($text)])],
            usage: new Usage(
                inputTokens: (int) ($usageRaw['prompt_tokens'] ?? 0),
                outputTokens: (int) ($usageRaw['completion_tokens'] ?? 0),
                reasoningTokens: 0,
            ),
            finishReason: (string) ($choice['finish_reason'] ?? 'stop'),
        );
    }

    protected function parseStreamResponse(Request $request, HttpResponse $response): \Traversable
    {
        yield new StreamEvent(StreamEventType::STREAM_START, ['provider' => $this->name()]);
        foreach (preg_split('/\R/', $response->body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, 5));
            if ($payload === '[DONE]') {
                break;
            }
            $data = json_decode($payload, true);
            if (!is_array($data)) {
                yield new StreamEvent(StreamEventType::PROVIDER_EVENT, ['raw' => $payload]);
                continue;
            }
            $delta = $data['choices'][0]['delta']['content'] ?? null;
            if ($delta !== null) {
                yield new StreamEvent(StreamEventType::TEXT_DELTA, ['text' => (string) $delta]);
            } else {
                yield new StreamEvent(StreamEventType::PROVIDER_EVENT, ['raw' => $data]);
            }
        }
        yield new StreamEvent(StreamEventType::FINISH, ['usage' => new Usage()]);
    }
}
