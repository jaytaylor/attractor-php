<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\LLM;

use Attractor\LLM\Client;
use Attractor\LLM\Errors\ConfigurationError;
use Attractor\LLM\Middleware;
use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Response;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testProviderRoutingUsesExplicitProvider(): void
    {
        $openai = new TestAdapter('openai');
        $anthropic = new TestAdapter('anthropic');

        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($openai);
        $client->registerAdapter($anthropic);

        $response = $client->complete(Request::fromPrompt('gpt-5.2', 'hello', 'anthropic'));

        $this->assertSame('anthropic', $response->provider);
        $this->assertCount(0, $openai->requests);
        $this->assertCount(1, $anthropic->requests);
    }

    public function testProviderRoutingUsesDefaultProvider(): void
    {
        $openai = new TestAdapter('openai');
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($openai);

        $response = $client->complete(Request::fromPrompt('gpt-5.2', 'hello'));
        $this->assertSame('openai', $response->provider);
    }

    public function testThrowsWhenNoProviderConfigured(): void
    {
        $client = new Client();

        $this->expectException(ConfigurationError::class);
        $client->complete(Request::fromPrompt('gpt-5.2', 'hello'));
    }

    public function testMiddlewareOrderIsRequestForwardResponseReverse(): void
    {
        $adapter = new TestAdapter('openai');
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $events = [];
        $client->use(new class($events) implements Middleware {
            /** @var array<int, string> */
            private array $events;

            /** @param array<int, string> $events */
            public function __construct(array &$events)
            {
                $this->events = &$events;
            }

            public function handle(Request $request, callable $next): Response
            {
                $this->events[] = 'A:request';
                $response = $next($request);
                $this->events[] = 'A:response';

                return $response;
            }
        });
        $client->use(new class($events) implements Middleware {
            /** @var array<int, string> */
            private array $events;

            /** @param array<int, string> $events */
            public function __construct(array &$events)
            {
                $this->events = &$events;
            }

            public function handle(Request $request, callable $next): Response
            {
                $this->events[] = 'B:request';
                $response = $next($request);
                $this->events[] = 'B:response';

                return $response;
            }
        });

        $client->complete(Request::fromPrompt('gpt-5.2', 'hello'));

        $this->assertSame(['A:request', 'B:request', 'B:response', 'A:response'], $events);
    }

    public function testModelCatalogAvailable(): void
    {
        $client = new Client(defaultProvider: 'openai');
        $models = $client->listModels();
        $this->assertArrayHasKey('openai:gpt-5.2', $models);
        $this->assertSame('openai', $client->getModelInfo('openai:gpt-5.2')['provider']);
    }
}
