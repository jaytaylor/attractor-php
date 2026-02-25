<?php

declare(strict_types=1);

namespace Attractor\LLM;

use Attractor\LLM\Adapters\AnthropicMessagesAdapter;
use Attractor\LLM\Adapters\GeminiAdapter;
use Attractor\LLM\Adapters\OpenAIResponsesAdapter;
use Attractor\LLM\Errors\ConfigurationError;
use Attractor\LLM\Http\ArrayTransport;
use Attractor\LLM\Http\HttpResponse;
use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Response;

final class Client
{
    /** @var array<string, ProviderAdapter> */
    private array $adapters = [];

    /** @var list<Middleware> */
    private array $middlewares = [];

    private readonly ModelCatalog $modelCatalog;

    public function __construct(
        private ?string $defaultProvider = null,
        ?ModelCatalog $modelCatalog = null,
    ) {
        $this->modelCatalog = $modelCatalog ?? new ModelCatalog();
    }

    public static function fromEnv(): self
    {
        $client = new self();

        $openAiKey = getenv('OPENAI_API_KEY') ?: null;
        $anthropicKey = getenv('ANTHROPIC_API_KEY') ?: null;
        $geminiKey = getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: null;

        if ($openAiKey !== null && $openAiKey !== '') {
            $client->registerAdapter(new OpenAIResponsesAdapter(
                apiKey: $openAiKey,
                transport: new ArrayTransport([new HttpResponse(500, body: '{"error":"transport not configured"}')]),
                baseUrl: (string) (getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1'),
            ));
            $client->defaultProvider = $client->defaultProvider ?? 'openai';
        }

        if ($anthropicKey !== null && $anthropicKey !== '') {
            $client->registerAdapter(new AnthropicMessagesAdapter(
                apiKey: $anthropicKey,
                transport: new ArrayTransport([new HttpResponse(500, body: '{"error":"transport not configured"}')]),
                baseUrl: (string) (getenv('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com/v1'),
            ));
            $client->defaultProvider = $client->defaultProvider ?? 'anthropic';
        }

        if ($geminiKey !== null && $geminiKey !== '') {
            $client->registerAdapter(new GeminiAdapter(
                apiKey: $geminiKey,
                transport: new ArrayTransport([new HttpResponse(500, body: '{"error":"transport not configured"}')]),
                baseUrl: (string) (getenv('GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com/v1beta'),
            ));
            $client->defaultProvider = $client->defaultProvider ?? 'gemini';
        }

        return $client;
    }

    public function registerAdapter(ProviderAdapter $adapter): void
    {
        $this->adapters[$adapter->name()] = $adapter;
    }

    public function use(Middleware $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function complete(Request $request): Response
    {
        $provider = $request->provider ?? $this->defaultProvider;
        if ($provider === null) {
            throw new ConfigurationError('no provider configured');
        }

        $adapter = $this->adapters[$provider] ?? null;
        if ($adapter === null) {
            throw new ConfigurationError("provider adapter not registered: {$provider}");
        }

        $runner = array_reduce(
            array_reverse($this->middlewares),
            fn (callable $next, Middleware $middleware): callable => fn (Request $req): Response => $middleware->handle($req, $next),
            fn (Request $req): Response => $adapter->complete($req),
        );

        return $runner($request);
    }

    /** @return \Traversable<int, \Attractor\LLM\Types\StreamEvent> */
    public function stream(Request $request): \Traversable
    {
        $provider = $request->provider ?? $this->defaultProvider;
        if ($provider === null) {
            throw new ConfigurationError('no provider configured');
        }

        $adapter = $this->adapters[$provider] ?? null;
        if ($adapter === null) {
            throw new ConfigurationError("provider adapter not registered: {$provider}");
        }

        return $adapter->stream($request);
    }

    public function setDefaultProvider(string $provider): void
    {
        $this->defaultProvider = $provider;
    }

    public function defaultProvider(): ?string
    {
        return $this->defaultProvider;
    }

    /** @return array<string, array<string, mixed>> */
    public function listModels(): array
    {
        return $this->modelCatalog->listModels();
    }

    /** @return array<string, mixed>|null */
    public function getModelInfo(string $id): ?array
    {
        return $this->modelCatalog->getModelInfo($id);
    }
}
