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

#[Group('provider-e2e')]
final class ProviderE2eTest extends TestCase
{
    public function testOpenAiSimpleGeneration(): void
    {
        $apiKey = getenv('OPENAI_API_KEY') ?: '';
        if ($apiKey === '') {
            self::markTestSkipped('OPENAI_API_KEY not set');
        }

        $model = (string) (getenv('OPENAI_E2E_MODEL') ?: 'gpt-5.2');
        $adapter = new OpenAIResponsesAdapter(
            apiKey: $apiKey,
            transport: new NativeHttpTransport($this->timeoutMs()),
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

    public function testAnthropicSimpleGeneration(): void
    {
        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
        if ($apiKey === '') {
            self::markTestSkipped('ANTHROPIC_API_KEY not set');
        }

        $model = (string) (getenv('ANTHROPIC_E2E_MODEL') ?: 'claude-sonnet-4-5');
        $adapter = new AnthropicMessagesAdapter(
            apiKey: $apiKey,
            transport: new NativeHttpTransport($this->timeoutMs()),
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

    public function testGeminiSimpleGeneration(): void
    {
        $apiKey = getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '';
        if ($apiKey === '') {
            self::markTestSkipped('GEMINI_API_KEY or GOOGLE_API_KEY not set');
        }

        $model = (string) (getenv('GEMINI_E2E_MODEL') ?: 'gemini-2.0-flash');
        $adapter = new GeminiAdapter(
            apiKey: $apiKey,
            transport: new NativeHttpTransport($this->timeoutMs()),
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

    private function timeoutMs(): int
    {
        return (int) (getenv('E2E_HTTP_TIMEOUT_MS') ?: 30_000);
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
                    '%s provider E2E skipped due to transient provider error (%d): %s',
                    $provider,
                    $error->statusCode(),
                    $error->getMessage()
                ));
            }

            throw $error;
        } catch (\RuntimeException $error) {
            self::markTestSkipped(sprintf(
                '%s provider E2E skipped due to transient transport error: %s',
                $provider,
                $error->getMessage()
            ));
        }
    }
}
