<?php

declare(strict_types=1);

namespace Attractor\LLM\Adapters;

use Attractor\LLM\Http\HttpRequest;
use Attractor\LLM\Http\HttpResponse;
use Attractor\LLM\Tools\ToolCall;
use Attractor\LLM\Types\ContentKind;
use Attractor\LLM\Types\ContentPart;
use Attractor\LLM\Types\Message;
use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Response;
use Attractor\LLM\Types\Role;
use Attractor\LLM\Types\StreamEvent;
use Attractor\LLM\Types\StreamEventType;
use Attractor\LLM\Types\Usage;

final class AnthropicMessagesAdapter extends BaseHttpAdapter
{
    public function name(): string
    {
        return 'anthropic';
    }

    protected function buildCompleteRequest(Request $request): HttpRequest
    {
        $systemTexts = [];
        $messages = [];

        foreach ($request->resolvedMessages() as $message) {
            if (in_array($message->role, [Role::SYSTEM, Role::DEVELOPER], true)) {
                $systemTexts[] = $message->text();
                continue;
            }

            $messages[] = [
                'role' => $message->role === Role::TOOL ? 'user' : $message->role,
                'content' => array_map(fn (ContentPart $part): array => $this->toAnthropicContent($part), $message->content),
            ];
        }

        $payload = [
            'model' => $request->model,
            'system' => implode("\n", array_filter($systemTexts)),
            'messages' => $messages,
            'max_tokens' => 2048,
            'stream' => false,
        ];

        if (($request->providerOptions['anthropic']['auto_cache'] ?? true) && $payload['system'] !== '') {
            $payload['system'] = [
                [
                    'type' => 'text',
                    'text' => $payload['system'],
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
        }

        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ];

        if (isset($payload['system'][0]['cache_control'])) {
            $headers['anthropic-beta'] = 'prompt-caching-2024-07-31';
        }

        return new HttpRequest(
            method: 'POST',
            url: rtrim($this->baseUrl, '/') . '/messages',
            headers: $headers,
            body: json_encode($payload, JSON_THROW_ON_ERROR),
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
        $content = [];
        $toolCalls = [];

        foreach (($data['content'] ?? []) as $part) {
            $type = (string) ($part['type'] ?? '');
            if ($type === 'text') {
                $content[] = ContentPart::text((string) ($part['text'] ?? ''));
            } elseif ($type === 'thinking') {
                $content[] = new ContentPart(ContentKind::THINKING, [
                    'text' => (string) ($part['thinking'] ?? ''),
                    'signature' => (string) ($part['signature'] ?? ''),
                    'redacted' => (bool) ($part['redacted'] ?? false),
                ]);
            } elseif ($type === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: (string) ($part['id'] ?? uniqid('tool_', true)),
                    name: (string) ($part['name'] ?? ''),
                    arguments: is_array($part['input'] ?? null) ? $part['input'] : [],
                );
            }
        }

        $usageRaw = $data['usage'] ?? [];
        $usage = new Usage(
            inputTokens: (int) ($usageRaw['input_tokens'] ?? 0),
            outputTokens: (int) ($usageRaw['output_tokens'] ?? 0),
            reasoningTokens: 0,
            cacheReadTokens: (int) ($usageRaw['cache_read_input_tokens'] ?? 0),
            cacheWriteTokens: (int) ($usageRaw['cache_creation_input_tokens'] ?? 0),
        );

        return new Response(
            provider: 'anthropic',
            model: (string) ($data['model'] ?? $request->model),
            messages: [new Message(Role::ASSISTANT, $content === [] ? [ContentPart::text('')] : $content)],
            usage: $usage,
            finishReason: (string) ($data['stop_reason'] ?? 'stop'),
            toolCalls: $toolCalls,
        );
    }

    protected function parseStreamResponse(Request $request, HttpResponse $response): \Traversable
    {
        yield new StreamEvent(StreamEventType::STREAM_START, ['provider' => 'anthropic']);
        foreach (preg_split('/\R/', $response->body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
            $data = json_decode($payload, true);
            if (!is_array($data)) {
                yield new StreamEvent(StreamEventType::PROVIDER_EVENT, ['raw' => $payload]);
                continue;
            }

            $type = (string) ($data['type'] ?? '');
            if ($type === 'content_block_delta' && (($data['delta']['type'] ?? '') === 'text_delta')) {
                yield new StreamEvent(StreamEventType::TEXT_DELTA, ['text' => (string) ($data['delta']['text'] ?? '')]);
            } else {
                yield new StreamEvent(StreamEventType::PROVIDER_EVENT, ['raw' => $data]);
            }
        }

        yield new StreamEvent(StreamEventType::FINISH, ['usage' => new Usage()]);
    }

    /** @return array<string, mixed> */
    private function toAnthropicContent(ContentPart $part): array
    {
        if ($part->kind === ContentKind::TEXT) {
            return ['type' => 'text', 'text' => $part->textValue()];
        }
        if ($part->kind === ContentKind::IMAGE) {
            if (isset($part->data['url'])) {
                return ['type' => 'image', 'source' => ['type' => 'url', 'url' => $part->data['url']]];
            }
            if (isset($part->data['data'])) {
                return ['type' => 'image', 'source' => [
                    'type' => 'base64',
                    'media_type' => (string) ($part->data['mime_type'] ?? 'image/png'),
                    'data' => (string) $part->data['data'],
                ]];
            }
        }

        return ['type' => $part->kind, 'data' => $part->data];
    }
}
