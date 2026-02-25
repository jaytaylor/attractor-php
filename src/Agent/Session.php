<?php

declare(strict_types=1);

namespace Attractor\Agent;

use Attractor\Agent\Exec\ToolOutputLimiter;
use Attractor\LLM\Client;
use Attractor\LLM\Tools\ToolCall;
use Attractor\LLM\Types\ContentPart;
use Attractor\LLM\Types\Message;
use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Role;

final class Session
{
    private const STATE_IDLE = 'idle';
    private const STATE_RUNNING = 'running';
    private const STATE_CLOSED = 'closed';

    private string $state = self::STATE_IDLE;

    /** @var list<Turn> */
    private array $history = [];

    /** @var list<string> */
    private array $steerQueue = [];

    /** @var list<string> */
    private array $followUpQueue = [];

    private int $turnCount = 0;

    /** @var list<string> */
    private array $recentToolSignatures = [];

    public function __construct(
        private readonly ProviderProfile $profile,
        private readonly ExecutionEnvironment $env,
        private readonly Client $llm,
        private readonly ?SessionConfig $config = null,
        private readonly ?EventEmitter $events = null,
        private readonly int $depth = 0,
    ) {
        $this->events?->emit(new SessionEvent('SESSION_START', ['provider' => $profile->id()]));
    }

    public function submit(string $input): SessionResult
    {
        $cfg = $this->config ?? new SessionConfig();
        if ($this->state === self::STATE_CLOSED) {
            throw new \RuntimeException('session closed');
        }
        if ($this->turnCount >= $cfg->maxTurns) {
            throw new \RuntimeException('session max_turns reached');
        }

        $this->state = self::STATE_RUNNING;
        $this->turnCount++;

        $docs = ProjectDocs::discover($this->env->workingDirectory(), $this->profile->id());
        $systemPrompt = $this->profile->buildSystemPrompt($this->env, $docs);

        $messages = [Message::fromText(Role::SYSTEM, $systemPrompt)];
        foreach ($this->history as $turn) {
            $messages[] = Message::fromText($turn->role, $turn->text);
        }
        $messages[] = Message::fromText(Role::USER, $input);

        $this->history[] = new Turn('user', Role::USER, $input);
        $this->events?->emit(new SessionEvent('TURN_START', ['input' => $input]));

        $assistantText = '';
        $round = 0;

        while (true) {
            $round++;
            if ($round > $cfg->maxToolRoundsPerInput) {
                $this->events?->emit(new SessionEvent('ROUND_LIMIT_REACHED', ['round' => $round]));
                break;
            }

            $response = $this->llm->complete(new Request(
                provider: $this->profile->id(),
                model: $this->profile->model(),
                messages: $messages,
                tools: $this->profile->tools(),
                providerOptions: $this->profile->providerOptions() ?? [],
            ));

            $toolCalls = $response->toolCalls;
            if ($toolCalls === []) {
                $assistantText = $response->text();
                if ($assistantText !== '') {
                    $this->history[] = new Turn('assistant', Role::ASSISTANT, $assistantText);
                }
                break;
            }

            $signature = implode('|', array_map(fn (ToolCall $call): string => $call->name, $toolCalls));
            $this->recentToolSignatures[] = $signature;
            if (count($this->recentToolSignatures) >= 2) {
                $count = count($this->recentToolSignatures);
                if ($this->recentToolSignatures[$count - 1] === $this->recentToolSignatures[$count - 2]) {
                    $this->events?->emit(new SessionEvent('LOOP_WARNING', ['signature' => $signature]));
                }
            }

            $assistantParts = [ContentPart::text($response->text())];
            foreach ($toolCalls as $toolCall) {
                $assistantParts[] = new ContentPart('tool_call', [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'arguments' => $toolCall->arguments,
                ]);
            }
            $messages[] = new Message(Role::ASSISTANT, $assistantParts);

            foreach ($toolCalls as $toolCall) {
                $this->events?->emit(new SessionEvent('TOOL_CALL_START', [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'arguments' => $toolCall->arguments,
                ]));

                $tool = $this->profile->toolRegistry()->get($toolCall->name);
                $result = null;
                if ($tool === null) {
                    $result = ['is_error' => true, 'error' => 'unknown tool'];
                } else {
                    try {
                        $result = $tool->execute($toolCall->arguments, $this->env);
                    } catch (\Throwable $t) {
                        $result = ['is_error' => true, 'error' => $t->getMessage()];
                    }
                }

                $full = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                $truncated = ToolOutputLimiter::truncate(
                    output: $full,
                    maxChars: $this->toolCharLimit($toolCall->name, $cfg),
                    maxLines: $this->toolLineLimit($toolCall->name, $cfg),
                );

                $this->events?->emit(new SessionEvent('TOOL_CALL_END', [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'result' => $truncated,
                    'full_result' => $full,
                ]));

                $messages[] = new Message(Role::TOOL, [
                    new ContentPart('tool_result', [
                        'tool_call_id' => $toolCall->id,
                        'name' => $toolCall->name,
                        'is_error' => (bool) ($result['is_error'] ?? false),
                        'content' => $truncated,
                    ]),
                ]);
            }

            while ($this->steerQueue !== []) {
                $steer = array_shift($this->steerQueue);
                if ($steer === null) {
                    continue;
                }

                $this->history[] = new Turn('steering', Role::USER, $steer);
                $messages[] = Message::fromText(Role::USER, $steer);
            }
        }

        $this->state = self::STATE_IDLE;
        $this->events?->emit(new SessionEvent('TURN_END', ['text' => $assistantText]));

        $events = $this->events instanceof BufferedEventEmitter ? $this->events->all() : [];

        while ($this->followUpQueue !== []) {
            $follow = array_shift($this->followUpQueue);
            if ($follow !== null) {
                $this->history[] = new Turn('follow_up', Role::USER, $follow);
            }
        }

        return new SessionResult($assistantText, $events);
    }

    public function steer(string $message): void
    {
        $this->steerQueue[] = $message;
    }

    public function followUp(string $message): void
    {
        $this->followUpQueue[] = $message;
    }

    public function close(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->events?->emit(new SessionEvent('SESSION_END'));
    }

    /** @return list<Turn> */
    public function history(): array
    {
        return $this->history;
    }

    private function toolCharLimit(string $toolName, SessionConfig $cfg): int
    {
        return match ($toolName) {
            'read_file' => $cfg->readFileMaxChars,
            'grep' => $cfg->grepMaxChars,
            'glob' => $cfg->globMaxChars,
            default => $cfg->shellMaxChars,
        };
    }

    private function toolLineLimit(string $toolName, SessionConfig $cfg): ?int
    {
        return match ($toolName) {
            'grep' => $cfg->grepMaxLines,
            'glob' => $cfg->globMaxLines,
            'shell' => $cfg->shellMaxLines,
            default => null,
        };
    }
}
