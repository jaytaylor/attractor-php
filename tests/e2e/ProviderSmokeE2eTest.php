<?php

declare(strict_types=1);

namespace Attractor\Tests\E2E;

use Attractor\LLM\Adapters\AnthropicMessagesAdapter;
use Attractor\LLM\Adapters\GeminiAdapter;
use Attractor\LLM\Adapters\OpenAIResponsesAdapter;
use Attractor\LLM\Errors\ProviderError;
use Attractor\LLM\Http\NativeHttpTransport;
use Attractor\LLM\Types\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('provider-smoke')]
final class ProviderSmokeE2eTest extends TestCase
{
    public function testOpenAiSimpleGenerationSmoke(): void
    {
        $apiKey = getenv('OPENAI_API_KEY') ?: '';
        if ($apiKey === '') {
            self::markTestSkipped('OPENAI_API_KEY not set');
        }

        $model = (string) (getenv('OPENAI_SMOKE_MODEL') ?: 'gpt-5.2');
        $adapter = new OpenAIResponsesAdapter(
            apiKey: $apiKey,
            transport: new NativeHttpTransport($this->smokeTimeoutMs()),
            baseUrl: (string) (getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1'),
        );

        $response = $this->completeOrSkipTransient(
            static fn (): \Attractor\LLM\Types\Response => $adapter->complete(
                new Request(provider: 'openai', model: $model, prompt: 'Reply with: smoke-ok')
            ),
            'openai'
        );
        $this->assertSame('openai', $response->provider);
        $this->assertNotSame('', trim($response->text()));
    }

    public function testAnthropicSimpleGenerationSmoke(): void
    {
        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
        if ($apiKey === '') {
            self::markTestSkipped('ANTHROPIC_API_KEY not set');
        }

        $model = (string) (getenv('ANTHROPIC_SMOKE_MODEL') ?: 'claude-sonnet-4-5');
        $adapter = new AnthropicMessagesAdapter(
            apiKey: $apiKey,
            transport: new NativeHttpTransport($this->smokeTimeoutMs()),
            baseUrl: (string) (getenv('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com/v1'),
        );

        $response = $this->completeOrSkipTransient(
            static fn (): \Attractor\LLM\Types\Response => $adapter->complete(
                new Request(provider: 'anthropic', model: $model, prompt: 'Reply with: smoke-ok')
            ),
            'anthropic'
        );
        $this->assertSame('anthropic', $response->provider);
        $this->assertNotSame('', trim($response->text()));
    }

    public function testGeminiSimpleGenerationSmoke(): void
    {
        $apiKey = getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '';
        if ($apiKey === '') {
            self::markTestSkipped('GEMINI_API_KEY or GOOGLE_API_KEY not set');
        }

        $model = (string) (getenv('GEMINI_SMOKE_MODEL') ?: 'gemini-2.0-flash');
        $adapter = new GeminiAdapter(
            apiKey: $apiKey,
            transport: new NativeHttpTransport($this->smokeTimeoutMs()),
            baseUrl: (string) (getenv('GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com/v1beta'),
        );

        $response = $this->completeOrSkipTransient(
            static fn (): \Attractor\LLM\Types\Response => $adapter->complete(
                new Request(provider: 'gemini', model: $model, prompt: 'Reply with: smoke-ok')
            ),
            'gemini'
        );
        $this->assertSame('gemini', $response->provider);
        $this->assertNotSame('', trim($response->text()));
    }

    private function smokeTimeoutMs(): int
    {
        return (int) (getenv('SMOKE_HTTP_TIMEOUT_MS') ?: 30_000);
    }

    private function completeOrSkipTransient(callable $complete, string $provider): \Attractor\LLM\Types\Response
    {
        try {
            /** @var \Attractor\LLM\Types\Response $response */
            $response = $complete();

            return $response;
        } catch (ProviderError $error) {
            if ($error->retryable()) {
                self::markTestSkipped(sprintf(
                    '%s smoke skipped due to transient provider error (%d): %s',
                    $provider,
                    $error->statusCode(),
                    $error->getMessage()
                ));
            }

            throw $error;
        } catch (\RuntimeException $error) {
            self::markTestSkipped(sprintf(
                '%s smoke skipped due to transient transport error: %s',
                $provider,
                $error->getMessage()
            ));
        }
    }
}
