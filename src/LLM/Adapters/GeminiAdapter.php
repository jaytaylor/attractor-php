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

final class GeminiAdapter extends BaseHttpAdapter
{
    public function name(): string
    {
        return 'gemini';
    }

    protected function buildCompleteRequest(Request $request): HttpRequest
    {
        $contents = [];
        foreach ($request->resolvedMessages() as $message) {
            if ($message->role === Role::SYSTEM || $message->role === Role::DEVELOPER) {
                continue;
            }

            $parts = [];
            foreach ($message->content as $part) {
                $parts[] = $this->toGeminiPart($part);
            }
            $contents[] = [
                'role' => $message->role === Role::ASSISTANT ? 'model' : 'user',
                'parts' => $parts,
            ];
        }

        $payload = [
            'contents' => $contents,
        ];

        return new HttpRequest(
            method: 'POST',
            url: rtrim($this->baseUrl, '/') . '/models/' . $request->model . ':generateContent?key=' . rawurlencode($this->apiKey),
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    protected function buildStreamRequest(Request $request): HttpRequest
    {
        return new HttpRequest(
            method: 'POST',
            url: rtrim($this->baseUrl, '/') . '/models/' . $request->model . ':streamGenerateContent?alt=sse&key=' . rawurlencode($this->apiKey),
            headers: ['Content-Type' => 'application/json'],
            body: $this->buildCompleteRequest($request)->body,
        );
    }

    protected function parseCompleteResponse(Request $request, HttpResponse $response): Response
    {
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];
        $text = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            }
            if (isset($part['functionCall'])) {
                $fn = $part['functionCall'];
                $toolCalls[] = new ToolCall(
                    id: (string) ($fn['id'] ?? uniqid('gemini_tool_', true)),
                    name: (string) ($fn['name'] ?? ''),
                    arguments: is_array($fn['args'] ?? null) ? $fn['args'] : [],
                );
            }
        }

        $usageRaw = $data['usageMetadata'] ?? [];
        $usage = new Usage(
            inputTokens: (int) ($usageRaw['promptTokenCount'] ?? 0),
            outputTokens: (int) ($usageRaw['candidatesTokenCount'] ?? 0),
            reasoningTokens: (int) ($usageRaw['thoughtsTokenCount'] ?? 0),
            cacheReadTokens: (int) ($usageRaw['cachedContentTokenCount'] ?? 0),
            cacheWriteTokens: 0,
        );

        return new Response(
            provider: 'gemini',
            model: $request->model,
            messages: [new Message(Role::ASSISTANT, [ContentPart::text($text)])],
            usage: $usage,
            finishReason: (string) ($candidate['finishReason'] ?? 'stop'),
            toolCalls: $toolCalls,
        );
    }

    protected function parseStreamResponse(Request $request, HttpResponse $response): \Traversable
    {
        yield new StreamEvent(StreamEventType::STREAM_START, ['provider' => 'gemini']);
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

            $part = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if ($part !== null) {
                yield new StreamEvent(StreamEventType::TEXT_DELTA, ['text' => (string) $part]);
            } else {
                yield new StreamEvent(StreamEventType::PROVIDER_EVENT, ['raw' => $data]);
            }
        }

        yield new StreamEvent(StreamEventType::FINISH, ['usage' => new Usage()]);
    }

    /** @return array<string, mixed> */
    private function toGeminiPart(ContentPart $part): array
    {
        if ($part->kind === ContentKind::TEXT) {
            return ['text' => $part->textValue()];
        }

        if ($part->kind === ContentKind::IMAGE) {
            if (isset($part->data['data'])) {
                return [
                    'inlineData' => [
                        'mimeType' => (string) ($part->data['mime_type'] ?? 'image/png'),
                        'data' => (string) $part->data['data'],
                    ],
                ];
            }
            if (isset($part->data['url'])) {
                return ['fileData' => ['fileUri' => (string) $part->data['url']]];
            }
        }

        return ['text' => json_encode($part->data, JSON_THROW_ON_ERROR)];
    }
}
