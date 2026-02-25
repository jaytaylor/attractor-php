<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\Agent;

use Attractor\Agent\BufferedEventEmitter;
use Attractor\Agent\Exec\LocalExecutionEnvironment;
use Attractor\Agent\Session;
use Attractor\Agent\SessionConfig;
use Attractor\Agent\Tools\CoreTools;
use Attractor\Agent\Tools\Tool;
use Attractor\Agent\Tools\ToolRegistry;
use Attractor\LLM\Client;
use Attractor\LLM\Tools\ToolDefinition;
use Attractor\LLM\Types\ContentPart;
use Attractor\LLM\Types\Message;
use Attractor\LLM\Types\Response;
use Attractor\LLM\Types\Role;
use Attractor\Tests\Unit\LLM\TestAdapter;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/attractor-session-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function testSessionNaturalCompletionWithNoToolCalls(): void
    {
        $adapter = new TestAdapter('openai', [
            new Response('openai', 'gpt-5.2', [Message::fromText(Role::ASSISTANT, 'done')]),
        ]);
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $registry = new ToolRegistry();
        CoreTools::register($registry, 10_000);
        $profile = new TestProfile('openai', 'gpt-5.2', [], $registry);
        $emitter = new BufferedEventEmitter();

        $session = new Session($profile, new LocalExecutionEnvironment($this->tmpDir), $client, new SessionConfig(), $emitter);
        $result = $session->submit('hello');

        $this->assertSame('done', $result->text);
        $kinds = array_map(static fn ($e): string => $e->kind, $result->events);
        $this->assertContains('SESSION_START', $kinds);
        $this->assertContains('TURN_END', $kinds);
    }

    public function testToolDispatchUnknownToolReturnsErrorAndModelRecovers(): void
    {
        $adapter = new TestAdapter('openai', [
            TestAdapter::withToolCall('openai', 'missing_tool', []),
            new Response('openai', 'gpt-5.2', [Message::fromText(Role::ASSISTANT, 'recovered')]),
        ]);
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $registry = new ToolRegistry();
        $profile = new TestProfile('openai', 'gpt-5.2', [new ToolDefinition('missing_tool', 'x', ['type' => 'object'])], $registry);
        $emitter = new BufferedEventEmitter();
        $session = new Session($profile, new LocalExecutionEnvironment($this->tmpDir), $client, new SessionConfig(), $emitter);

        $result = $session->submit('do thing');
        $this->assertSame('recovered', $result->text);

        $toolEndEvents = array_values(array_filter($result->events, static fn ($event): bool => $event->kind === 'TOOL_CALL_END'));
        $this->assertCount(1, $toolEndEvents);
        $this->assertStringContainsString('unknown tool', $toolEndEvents[0]->data['full_result']);
    }

    public function testSteeringMessageInjectedAfterToolRound(): void
    {
        $adapter = new TestAdapter('openai', [
            TestAdapter::withToolCall('openai', 'echo', ['text' => 'abc']),
            new Response('openai', 'gpt-5.2', [Message::fromText(Role::ASSISTANT, 'done')]),
        ]);
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $registry = new ToolRegistry();
        $registry->register(new Tool(
            name: 'echo',
            description: 'echo text',
            parametersSchema: ['type' => 'object'],
            handler: static fn (array $args, \Attractor\Agent\ExecutionEnvironment $env): array => ['text' => (string) ($args['text'] ?? '')],
        ));
        $profile = new TestProfile('openai', 'gpt-5.2', [new ToolDefinition('echo', 'echo', ['type' => 'object'])], $registry);
        $session = new Session($profile, new LocalExecutionEnvironment($this->tmpDir), $client, new SessionConfig(), new BufferedEventEmitter());

        $session->steer('please be concise');
        $result = $session->submit('run echo');

        $this->assertSame('done', $result->text);
        $historyText = array_map(static fn ($turn): string => $turn->text, $session->history());
        $this->assertContains('please be concise', $historyText);
    }

    public function testLoopDetectionEmitsWarningOnRepeatedToolPattern(): void
    {
        $adapter = new TestAdapter('openai', [
            TestAdapter::withToolCall('openai', 'echo', ['text' => 'one']),
            TestAdapter::withToolCall('openai', 'echo', ['text' => 'two']),
            new Response('openai', 'gpt-5.2', [new Message(Role::ASSISTANT, [ContentPart::text('stop')])]),
        ]);

        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $registry = new ToolRegistry();
        $registry->register(new Tool(
            name: 'echo',
            description: 'echo text',
            parametersSchema: ['type' => 'object'],
            handler: static fn (array $args, \Attractor\Agent\ExecutionEnvironment $env): array => ['echo' => $args],
        ));

        $profile = new TestProfile('openai', 'gpt-5.2', [new ToolDefinition('echo', 'echo', ['type' => 'object'])], $registry);
        $emitter = new BufferedEventEmitter();
        $session = new Session($profile, new LocalExecutionEnvironment($this->tmpDir), $client, new SessionConfig(), $emitter);

        $result = $session->submit('loop');
        $warnings = array_values(array_filter($result->events, static fn ($event): bool => $event->kind === 'LOOP_WARNING'));
        $this->assertNotEmpty($warnings);
    }

    public function testToolOutputTruncationKeepsFullOutputInEvent(): void
    {
        $huge = str_repeat('a', 2000);
        $adapter = new TestAdapter('openai', [
            TestAdapter::withToolCall('openai', 'huge', []),
            new Response('openai', 'gpt-5.2', [Message::fromText(Role::ASSISTANT, 'done')]),
        ]);

        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $registry = new ToolRegistry();
        $registry->register(new Tool(
            name: 'huge',
            description: 'returns huge output',
            parametersSchema: ['type' => 'object'],
            handler: static fn (array $args, \Attractor\Agent\ExecutionEnvironment $env): array => ['payload' => $huge],
        ));

        $profile = new TestProfile('openai', 'gpt-5.2', [new ToolDefinition('huge', 'h', ['type' => 'object'])], $registry);
        $cfg = new SessionConfig(shellMaxChars: 120);
        $emitter = new BufferedEventEmitter();
        $session = new Session($profile, new LocalExecutionEnvironment($this->tmpDir), $client, $cfg, $emitter);

        $result = $session->submit('go');
        $event = array_values(array_filter($result->events, static fn ($e): bool => $e->kind === 'TOOL_CALL_END'))[0];

        $this->assertStringContainsString('WARNING: Tool output was truncated', $event->data['result']);
        $this->assertStringContainsString($huge, $event->data['full_result']);
    }

    public function testFollowUpQueuedAfterInputCompletes(): void
    {
        $adapter = new TestAdapter('openai', [
            new Response('openai', 'gpt-5.2', [Message::fromText(Role::ASSISTANT, 'first')]),
        ]);
        $client = new Client(defaultProvider: 'openai');
        $client->registerAdapter($adapter);

        $registry = new ToolRegistry();
        $profile = new TestProfile('openai', 'gpt-5.2', [], $registry);
        $session = new Session($profile, new LocalExecutionEnvironment($this->tmpDir), $client, new SessionConfig(), new BufferedEventEmitter());

        $session->followUp('next task');
        $session->submit('initial');

        $historyText = array_map(static fn ($turn): string => $turn->text, $session->history());
        $this->assertContains('next task', $historyText);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
