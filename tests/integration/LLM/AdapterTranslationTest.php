<?php

declare(strict_types=1);

namespace Attractor\Tests\Integration\LLM;

use Attractor\LLM\Adapters\AnthropicMessagesAdapter;
use Attractor\LLM\Adapters\GeminiAdapter;
use Attractor\LLM\Adapters\OpenAIResponsesAdapter;
use Attractor\LLM\Http\ArrayTransport;
use Attractor\LLM\Http\HttpResponse;
use Attractor\LLM\Types\ContentKind;
use Attractor\LLM\Types\ContentPart;
use Attractor\LLM\Types\Message;
use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Role;
use PHPUnit\Framework\TestCase;

final class AdapterTranslationTest extends TestCase
{
    public function testOpenAIAdapterTranslatesCompletionAndUsage(): void
    {
        $transport = new ArrayTransport([
            new HttpResponse(200, [
                'x-ratelimit-limit-requests' => '100',
                'x-ratelimit-remaining-requests' => '90',
            ], json_encode([
                'model' => 'gpt-5.2',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'hello']],
                ]],
                'usage' => [
                    'input_tokens' => 12,
                    'output_tokens' => 4,
                    'output_tokens_details' => ['reasoning_tokens' => 2],
                    'input_tokens_details' => ['cached_tokens' => 6],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $adapter = new OpenAIResponsesAdapter('key', $transport, 'https://api.openai.com/v1');
        $response = $adapter->complete(Request::fromPrompt('gpt-5.2', 'hi'));

        $this->assertSame('hello', $response->text());
        $this->assertSame(2, $response->usage->reasoningTokens);
        $this->assertSame(6, $response->usage->cacheReadTokens);
        $request = $transport->requests()[0];
        $payload = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('gpt-5.2', $payload['model']);
        $this->assertSame('input_text', $payload['input'][0]['content'][0]['type']);
    }

    public function testAnthropicAdapterThinkingAndCachingHeader(): void
    {
        $transport = new ArrayTransport([
            new HttpResponse(200, [], json_encode([
                'model' => 'claude-sonnet-4-5',
                'content' => [
                    ['type' => 'thinking', 'thinking' => '...', 'signature' => 'sig123'],
                    ['type' => 'text', 'text' => 'done'],
                ],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                    'cache_read_input_tokens' => 3,
                    'cache_creation_input_tokens' => 2,
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $adapter = new AnthropicMessagesAdapter('key', $transport, 'https://api.anthropic.com/v1');
        $request = new Request(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            messages: [
                Message::fromText(Role::SYSTEM, 'system prompt'),
                Message::fromText(Role::USER, 'hello'),
            ],
            providerOptions: ['anthropic' => ['auto_cache' => true]],
        );

        $response = $adapter->complete($request);

        $this->assertSame('done', $response->text());
        $this->assertSame(ContentKind::THINKING, $response->reasoning()[0]->kind);
        $sent = $transport->requests()[0];
        $this->assertArrayHasKey('anthropic-beta', $sent->headers);
    }

    public function testGeminiAdapterMapsThoughtTokensAndImageData(): void
    {
        $transport = new ArrayTransport([
            new HttpResponse(200, [], json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'ok']]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 20,
                    'candidatesTokenCount' => 8,
                    'thoughtsTokenCount' => 5,
                    'cachedContentTokenCount' => 11,
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $adapter = new GeminiAdapter('key', $transport, 'https://generativelanguage.googleapis.com/v1beta');
        $request = new Request(
            provider: 'gemini',
            model: 'gemini-2.0-flash',
            messages: [new Message(Role::USER, [
                ContentPart::text('describe image'),
                new ContentPart(ContentKind::IMAGE, ['data' => 'abc', 'mime_type' => 'image/png']),
            ])],
        );

        $response = $adapter->complete($request);
        $this->assertSame('ok', $response->text());
        $this->assertSame(5, $response->usage->reasoningTokens);
        $this->assertSame(11, $response->usage->cacheReadTokens);

        $sent = $transport->requests()[0];
        $payload = json_decode($sent->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('inlineData', $payload['contents'][0]['parts'][1]);
    }

    public function testStreamParsingProducesProviderEventsForUnknownChunks(): void
    {
        $transport = new ArrayTransport([
            new HttpResponse(200, [], implode("\n", [
                'data: {"type":"response.output_text.delta","delta":"he"}',
                'data: {"unexpected":true}',
                'data: [DONE]',
            ])),
        ]);
        $adapter = new OpenAIResponsesAdapter('key', $transport, 'https://api.openai.com/v1');

        $events = iterator_to_array($adapter->stream(Request::fromPrompt('gpt-5.2', 'hi')));
        $types = array_map(fn ($e): string => $e->type, $events);
        $this->assertContains('text_delta', $types);
        $this->assertContains('provider_event', $types);
        $this->assertSame('he', $events[1]->data['text']);
    }
}
