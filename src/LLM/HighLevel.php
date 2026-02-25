<?php

declare(strict_types=1);

namespace Attractor\LLM;

use Attractor\LLM\Errors\NoObjectGeneratedError;
use Attractor\LLM\Tools\ToolCall;
use Attractor\LLM\Tools\ToolResult;
use Attractor\LLM\Types\ContentKind;
use Attractor\LLM\Types\ContentPart;
use Attractor\LLM\Types\GenerateObjectResult;
use Attractor\LLM\Types\GenerateResult;
use Attractor\LLM\Types\Message;
use Attractor\LLM\Types\Request;
use Attractor\LLM\Types\Response;
use Attractor\LLM\Types\Role;
use Attractor\LLM\Types\StepResult;
use Attractor\LLM\Types\StreamAccumulator;

final class HighLevel
{
    /**
     * @param array<string, mixed> $params
     */
    public static function generate(array $params, ?Client $client = null): GenerateResult
    {
        $request = self::requestFromParams($params);
        $client ??= DefaultClient::get();

        $messages = $request->resolvedMessages();
        $steps = [];
        $round = 0;
        $maxRounds = max(0, $request->maxToolRounds);

        while (true) {
            $round++;
            $response = $client->complete(new Request(
                provider: $request->provider,
                model: $request->model,
                messages: $messages,
                tools: $request->tools,
                toolChoice: $request->toolChoice,
                providerOptions: $request->providerOptions,
                maxToolRounds: $request->maxToolRounds,
                maxRetries: $request->maxRetries,
                timeoutMs: $request->timeoutMs,
            ));

            $toolCalls = $response->toolCalls;
            if ($toolCalls === [] || $maxRounds === 0 || $round > $maxRounds) {
                $steps[] = new StepResult(response: $response, toolCalls: $toolCalls, toolResults: []);
                return new GenerateResult(text: $response->text(), response: $response, steps: $steps);
            }

            $toolResults = self::executeToolCalls($request, $toolCalls);
            $steps[] = new StepResult(response: $response, toolCalls: $toolCalls, toolResults: $toolResults);

            $assistantParts = [ContentPart::text($response->text())];
            foreach ($toolCalls as $call) {
                $assistantParts[] = new ContentPart(ContentKind::TOOL_CALL, [
                    'id' => $call->id,
                    'name' => $call->name,
                    'arguments' => $call->arguments,
                ]);
            }

            $messages[] = new Message(Role::ASSISTANT, $assistantParts);
            foreach ($toolResults as $toolResult) {
                $messages[] = new Message(Role::TOOL, [
                    new ContentPart(ContentKind::TOOL_RESULT, [
                        'tool_call_id' => $toolResult->toolCallId,
                        'name' => $toolResult->name,
                        'is_error' => $toolResult->isError,
                        'result' => $toolResult->result,
                    ]),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return \Traversable<int, \Attractor\LLM\Types\StreamEvent>
     */
    public static function stream(array $params, ?Client $client = null): \Traversable
    {
        $request = self::requestFromParams($params);
        $client ??= DefaultClient::get();

        return $client->stream($request);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $schema
     */
    public static function generateObject(array $params, array $schema, ?Client $client = null): GenerateObjectResult
    {
        $result = self::generate($params, $client);
        $decoded = json_decode($result->text, true);
        if (!is_array($decoded)) {
            throw new NoObjectGeneratedError('model did not return a JSON object');
        }

        $required = $schema['required'] ?? [];
        foreach ($required as $key) {
            if (!array_key_exists((string) $key, $decoded)) {
                throw new NoObjectGeneratedError('generated object missing required key: ' . $key);
            }
        }

        return new GenerateObjectResult(object: $decoded, result: $result);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function streamResult(array $params, ?Client $client = null): \Attractor\LLM\Types\StreamResult
    {
        $accumulator = new StreamAccumulator();
        foreach (self::stream($params, $client) as $event) {
            $accumulator->consume($event);
        }

        return $accumulator->finalize();
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function requestFromParams(array $params): Request
    {
        $provider = isset($params['provider']) ? (string) $params['provider'] : null;
        $model = (string) ($params['model'] ?? '');
        $prompt = isset($params['prompt']) ? (string) $params['prompt'] : null;

        $messages = [];
        if (isset($params['messages']) && is_array($params['messages'])) {
            foreach ($params['messages'] as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $role = (string) ($m['role'] ?? Role::USER);
                $text = (string) ($m['text'] ?? '');
                $messages[] = Message::fromText($role, $text);
            }
        }

        $tools = is_array($params['tools'] ?? null) ? $params['tools'] : [];
        $maxToolRounds = isset($params['max_tool_rounds']) ? (int) $params['max_tool_rounds'] : 4;
        $maxRetries = isset($params['max_retries']) ? (int) $params['max_retries'] : 2;

        return new Request(
            provider: $provider,
            model: $model,
            messages: $messages,
            prompt: $prompt,
            tools: $tools,
            toolChoice: $params['tool_choice'] ?? null,
            providerOptions: is_array($params['provider_options'] ?? null) ? $params['provider_options'] : [],
            maxToolRounds: $maxToolRounds,
            maxRetries: $maxRetries,
            timeoutMs: isset($params['timeout_ms']) ? (int) $params['timeout_ms'] : null,
        );
    }

    /**
     * @param list<ToolCall> $toolCalls
     * @return list<ToolResult>
     */
    private static function executeToolCalls(Request $request, array $toolCalls): array
    {
        $toolsByName = [];
        foreach ($request->tools as $tool) {
            $toolsByName[$tool->name] = $tool;
        }

        $results = [];
        foreach ($toolCalls as $toolCall) {
            $tool = $toolsByName[$toolCall->name] ?? null;
            if ($tool === null) {
                $results[] = new ToolResult(
                    toolCallId: $toolCall->id,
                    name: $toolCall->name,
                    result: ['error' => 'unknown tool'],
                    isError: true,
                );
                continue;
            }

            if (!$tool->isActive()) {
                continue;
            }

            try {
                $output = ($tool->execute)($toolCall->arguments);
                $results[] = new ToolResult(
                    toolCallId: $toolCall->id,
                    name: $toolCall->name,
                    result: is_array($output) ? $output : ['output' => $output],
                    isError: false,
                );
            } catch (\Throwable $t) {
                $results[] = new ToolResult(
                    toolCallId: $toolCall->id,
                    name: $toolCall->name,
                    result: ['error' => $t->getMessage()],
                    isError: true,
                );
            }
        }

        return $results;
    }
}
