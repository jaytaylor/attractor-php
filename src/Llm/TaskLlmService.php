<?php

declare(strict_types=1);

namespace AttractorPhp\Llm;

use AttractorPhp\Http\ApiError;

final class TaskLlmService
{
    /** @param array<string,mixed> $options */
    public function completeTask(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? (getenv('ATTRACTOR_TASK_PROVIDER') ?: getenv('ATTRACTOR_DOT_PROVIDER') ?: 'openai'))));
        if ($provider === '') {
            $provider = 'openai';
        }

        if ($provider === 'openai') {
            return $this->completeOpenAi($systemPrompt, $userPrompt, $options);
        }
        if ($provider === 'anthropic') {
            return $this->completeAnthropic($systemPrompt, $userPrompt, $options);
        }

        throw new ApiError(400, 'BAD_REQUEST', 'provider must be one of: openai, anthropic');
    }

    /** @param array<string,mixed> $options */
    private function completeOpenAi(string $systemPrompt, string $userPrompt, array $options): string
    {
        $apiKey = trim((string) (getenv('OPENAI_API_KEY') ?: ''));
        if ($apiKey === '') {
            throw new ApiError(500, 'CONFIG_ERROR', 'OPENAI_API_KEY is required for runtime tasks');
        }

        $model = trim((string) ($options['model'] ?? ''));
        if ($model === '') {
            $model = trim((string) (getenv('ATTRACTOR_OPENAI_MODEL') ?: getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini'));
        }

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [['type' => 'input_text', 'text' => $systemPrompt]],
                ],
                [
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $userPrompt]],
                ],
            ],
        ];

        $maxTokens = (int) ($options['maxTokens'] ?? 0);
        if ($maxTokens > 0) {
            $payload['max_output_tokens'] = $maxTokens;
        }
        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $baseUrl = rtrim((string) (getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1'), '/');
        $response = $this->postJson(
            $baseUrl . '/responses',
            [
                'authorization: Bearer ' . $apiKey,
                'content-type: application/json',
            ],
            $payload,
            'openai'
        );

        $outputText = trim((string) ($response['output_text'] ?? ''));
        if ($outputText !== '') {
            return $outputText;
        }

        $parts = [];
        foreach ((array) ($response['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (!is_array($content)) {
                    continue;
                }
                $text = trim((string) ($content['text'] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        $joined = trim(implode("\n", $parts));
        if ($joined !== '') {
            return $joined;
        }

        throw new ApiError(502, 'UPSTREAM_ERROR', 'openai response did not include text output');
    }

    /** @param array<string,mixed> $options */
    private function completeAnthropic(string $systemPrompt, string $userPrompt, array $options): string
    {
        $apiKey = trim((string) (getenv('ANTHROPIC_API_KEY') ?: ''));
        if ($apiKey === '') {
            throw new ApiError(500, 'CONFIG_ERROR', 'ANTHROPIC_API_KEY is required for runtime tasks');
        }

        $model = trim((string) ($options['model'] ?? ''));
        if ($model === '') {
            $model = trim((string) (getenv('ATTRACTOR_ANTHROPIC_MODEL') ?: getenv('ANTHROPIC_MODEL') ?: ''));
        }
        if ($model === '') {
            throw new ApiError(500, 'CONFIG_ERROR', 'Anthropic model is required; set ATTRACTOR_ANTHROPIC_MODEL, ANTHROPIC_MODEL, or request model');
        }

        $payload = [
            'model' => $model,
            'system' => $systemPrompt,
            'max_tokens' => max(256, (int) ($options['maxTokens'] ?? 1200)),
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $baseUrl = rtrim((string) (getenv('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com/v1'), '/');
        $response = $this->postJson(
            $baseUrl . '/messages',
            [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            $payload,
            'anthropic'
        );

        $parts = [];
        foreach ((array) ($response['content'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['type'] ?? '') !== 'text') {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $joined = trim(implode("\n", $parts));
        if ($joined !== '') {
            return $joined;
        }

        throw new ApiError(502, 'UPSTREAM_ERROR', 'anthropic response did not include text output');
    }

    /**
     * @param list<string> $headers
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function postJson(string $url, array $headers, array $payload, string $provider): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to encode provider payload');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiError(500, 'INTERNAL_ERROR', 'unable to initialize curl');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);

        $raw = curl_exec($ch);
        if (!is_string($raw)) {
            $error = curl_error($ch);
            throw new ApiError(502, 'UPSTREAM_ERROR', "{$provider} request failed: {$error}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiError(502, 'UPSTREAM_ERROR', "{$provider} returned non-JSON response");
        }

        if ($status < 200 || $status >= 300) {
            $message = trim((string) ($decoded['error']['message'] ?? $decoded['error'] ?? 'request failed'));
            if ($message === '') {
                $message = 'request failed';
            }
            throw new ApiError(502, 'UPSTREAM_ERROR', "{$provider} API error ({$status}): {$message}");
        }

        return $decoded;
    }
}
