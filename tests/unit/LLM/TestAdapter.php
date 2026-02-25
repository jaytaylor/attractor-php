<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\LLM;

use Attractor\LLM\ProviderAdapter;
use Attractor\LLM\Tools\ToolCall;
use Attractor\LLM\Types\ContentPart;
use Attractor\LLM\Types\Message;
use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Response;
use Attractor\LLM\Types\Role;
use Attractor\LLM\Types\StreamEvent;
use Attractor\LLM\Types\StreamEventType;
use Attractor\LLM\Types\Usage;

final class TestAdapter implements ProviderAdapter
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response> */
    public array $responses;

    /** @var list<list<StreamEvent>> */
    public array $streams;

    public function __construct(private readonly string $id, array $responses = [], array $streams = [])
    {
        $this->responses = $responses;
        $this->streams = $streams;
    }

    public function name(): string
    {
        return $this->id;
    }

    public function complete(Request $request): Response
    {
        $this->requests[] = $request;
        if ($this->responses !== []) {
            return array_shift($this->responses);
        }

        return new Response(
            provider: $this->id,
            model: $request->model,
            messages: [Message::fromText(Role::ASSISTANT, 'ok')],
            usage: new Usage(),
            finishReason: 'stop',
            toolCalls: [],
        );
    }

    public function stream(Request $request): \Traversable
    {
        $this->requests[] = $request;
        $events = $this->streams !== []
            ? array_shift($this->streams)
            : [
                new StreamEvent(StreamEventType::STREAM_START),
                new StreamEvent(StreamEventType::TEXT_DELTA, ['text' => 'ok']),
                new StreamEvent(StreamEventType::FINISH, ['usage' => new Usage()]),
            ];

        foreach ($events as $event) {
            yield $event;
        }
    }

    public static function withToolCall(string $provider, string $toolName, array $toolArgs = []): Response
    {
        return new Response(
            provider: $provider,
            model: 'test-model',
            messages: [new Message(Role::ASSISTANT, [ContentPart::text('')])],
            usage: new Usage(),
            finishReason: 'tool_calls',
            toolCalls: [new ToolCall('call_1', $toolName, $toolArgs)],
        );
    }
}
