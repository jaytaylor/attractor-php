<?php

declare(strict_types=1);

namespace Attractor\Tests\Integration\LLM;

use Attractor\LLM\Adapters\OpenAIResponsesAdapter;
use Attractor\LLM\Errors\AuthenticationError;
use Attractor\LLM\Errors\RateLimitError;
use Attractor\LLM\Http\ArrayTransport;
use Attractor\LLM\Http\HttpResponse;
use Attractor\LLM\Types\Request;
use PHPUnit\Framework\TestCase;

final class ErrorRetryTest extends TestCase
{
    public function test429RetriesAndSucceeds(): void
    {
        $transport = new ArrayTransport([
            new HttpResponse(429, ['Retry-After' => '1'], '{"error":"rate"}'),
            new HttpResponse(200, [], json_encode([
                'model' => 'gpt-5.2',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'ok']],
                ]],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $adapter = new OpenAIResponsesAdapter('key', $transport, 'https://api.openai.com/v1');
        $response = $adapter->complete(new Request(provider: 'openai', model: 'gpt-5.2', prompt: 'hi', maxRetries: 2));

        $this->assertSame('ok', $response->text());
        $this->assertCount(2, $transport->requests());
    }

    public function test429ThrowsWhenRetriesDisabled(): void
    {
        $transport = new ArrayTransport([
            new HttpResponse(429, ['Retry-After' => '2'], '{"error":"rate"}'),
        ]);
        $adapter = new OpenAIResponsesAdapter('key', $transport, 'https://api.openai.com/v1');

        $this->expectException(RateLimitError::class);
        $adapter->complete(new Request(provider: 'openai', model: 'gpt-5.2', prompt: 'hi', maxRetries: 0));
    }

    public function test401IsNotRetried(): void
    {
        $transport = new ArrayTransport([
            new HttpResponse(401, [], '{"error":"auth"}'),
            new HttpResponse(200, [], '{}'),
        ]);
        $adapter = new OpenAIResponsesAdapter('key', $transport, 'https://api.openai.com/v1');

        $this->expectException(AuthenticationError::class);
        try {
            $adapter->complete(new Request(provider: 'openai', model: 'gpt-5.2', prompt: 'hi', maxRetries: 3));
        } finally {
            $this->assertCount(1, $transport->requests());
        }
    }
}
