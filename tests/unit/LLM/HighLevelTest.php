<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\LLM;

use Attractor\LLM\Client;
use Attractor\LLM\Errors\NoObjectGeneratedError;
use Attractor\LLM\HighLevel;
use Attractor\LLM\Tools\ToolDefinition;
use Attractor\LLM\Types\ContentPart;
use Attractor\LLM\Types\Message;
use Attractor\LLM\Types\Response;
use Attractor\LLM\Types\Role;
use Attractor\LLM\Types\StreamEvent;
use Attractor\LLM\Types\StreamEventType;
use PHPUnit\Framework\TestCase;

final class HighLevelTest extends TestCase
{
    public function testGenerateWithPrompt(): void
    {
        $adapter = new TestAdapter('openai', [
            new Response('openai', 'gpt-5.2', [Message::fromText(Role::ASSISTANT, 'hello world')]),
        ]);

        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $result = HighLevel::generate([
            'provider' => 'openai',
            'model' => 'gpt-5.2',
            'prompt' => 'say hello',
        ], $client);

        $this->assertSame('hello world', $result->text);
        $this->assertCount(1, $result->steps);
    }

    public function testGenerateRejectsPromptAndMessagesTogether(): void
    {
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter(new TestAdapter('openai'));

        $this->expectException(\InvalidArgumentException::class);
        HighLevel::generate([
            'provider' => 'openai',
            'model' => 'gpt-5.2',
            'prompt' => 'x',
            'messages' => [['role' => 'user', 'text' => 'y']],
        ], $client);
    }

    public function testGenerateObjectParsesJson(): void
    {
        $adapter = new TestAdapter('openai', [
            new Response('openai', 'gpt-5.2', [Message::fromText(Role::ASSISTANT, '{"answer":"yes"}')]),
        ]);
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $result = HighLevel::generateObject([
            'provider' => 'openai',
            'model' => 'gpt-5.2',
            'prompt' => 'json please',
        ], ['required' => ['answer']], $client);

        $this->assertSame('yes', $result->object['answer']);
    }

    public function testGenerateObjectThrowsOnInvalidJson(): void
    {
        $adapter = new TestAdapter('openai', [
            new Response('openai', 'gpt-5.2', [Message::fromText(Role::ASSISTANT, 'not json')]),
        ]);
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $this->expectException(NoObjectGeneratedError::class);
        HighLevel::generateObject([
            'provider' => 'openai',
            'model' => 'gpt-5.2',
            'prompt' => 'json please',
        ], ['required' => ['answer']], $client);
    }

    public function testToolLoopExecutesActiveToolsAndContinues(): void
    {
        $adapter = new TestAdapter('openai', [
            TestAdapter::withToolCall('openai', 'add', ['a' => 2, 'b' => 3]),
            new Response('openai', 'gpt-5.2', [Message::fromText(Role::ASSISTANT, '5')]),
        ]);
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $tool = new ToolDefinition(
            name: 'add',
            description: 'add numbers',
            parametersSchema: ['type' => 'object'],
            execute: fn (array $args): array => ['sum' => ((int) $args['a']) + ((int) $args['b'])],
        );

        $result = HighLevel::generate([
            'provider' => 'openai',
            'model' => 'gpt-5.2',
            'prompt' => 'add',
            'tools' => [$tool],
            'max_tool_rounds' => 3,
        ], $client);

        $this->assertSame('5', $result->text);
        $this->assertCount(2, $result->steps);
        $this->assertCount(2, $adapter->requests);
    }

    public function testUnknownToolReturnsErrorToolResultAndContinues(): void
    {
        $adapter = new TestAdapter('openai', [
            TestAdapter::withToolCall('openai', 'missing_tool', ['q' => 'x']),
            new Response('openai', 'gpt-5.2', [Message::fromText(Role::ASSISTANT, 'recovered')]),
        ]);
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $result = HighLevel::generate([
            'provider' => 'openai',
            'model' => 'gpt-5.2',
            'prompt' => 'x',
            'tools' => [],
            'max_tool_rounds' => 2,
        ], $client);

        $this->assertSame('recovered', $result->text);
        $this->assertTrue($result->steps[0]->toolResults[0]->isError);
    }

    public function testStreamResultAccumulatesText(): void
    {
        $adapter = new TestAdapter('openai', [], [[
            new StreamEvent(StreamEventType::STREAM_START),
            new StreamEvent(StreamEventType::TEXT_DELTA, ['text' => 'hello ']),
            new StreamEvent(StreamEventType::TEXT_DELTA, ['text' => 'world']),
            new StreamEvent(StreamEventType::FINISH),
        ]]);
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $result = HighLevel::streamResult([
            'provider' => 'openai',
            'model' => 'gpt-5.2',
            'prompt' => 'x',
        ], $client);

        $this->assertSame('hello world', $result->text);
    }

    public function testMultimodalMessagePartsSupportedInRequestModel(): void
    {
        $part = new ContentPart('image', ['url' => 'https://example.com/img.png']);
        $msg = new Message(Role::USER, [ContentPart::text('describe'), $part]);

        $this->assertSame('describe', $msg->text());
        $this->assertSame('image', $msg->content[1]->kind);
    }
}
