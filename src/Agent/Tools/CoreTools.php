<?php

declare(strict_types=1);

namespace Attractor\Agent\Tools;

use Attractor\Agent\Exec\GrepOptions;

final class CoreTools
{
    public static function register(ToolRegistry $registry, int $defaultTimeoutMs): void
    {
        $registry->register(new Tool(
            name: 'read_file',
            description: 'Read a file with line numbers',
            parametersSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string'],
                    'offset' => ['type' => 'integer'],
                    'limit' => ['type' => 'integer'],
                ],
                'required' => ['path'],
            ],
            handler: static function (array $args, \Attractor\Agent\ExecutionEnvironment $env): array {
                $path = (string) ($args['path'] ?? '');
                $offset = isset($args['offset']) ? (int) $args['offset'] : null;
                $limit = isset($args['limit']) ? (int) $args['limit'] : null;

                return ['content' => $env->readFile($path, $offset, $limit)];
            },
        ));

        $registry->register(new Tool(
            name: 'write_file',
            description: 'Write full file content',
            parametersSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                ],
                'required' => ['path', 'content'],
            ],
            handler: static function (array $args, \Attractor\Agent\ExecutionEnvironment $env): array {
                $env->writeFile((string) $args['path'], (string) $args['content']);
                return ['ok' => true];
            },
        ));

        $registry->register(new Tool(
            name: 'edit_file',
            description: 'Edit file by replacing old_string with new_string',
            parametersSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string'],
                    'old_string' => ['type' => 'string'],
                    'new_string' => ['type' => 'string'],
                    'replace_all' => ['type' => 'boolean'],
                ],
                'required' => ['path', 'old_string', 'new_string'],
            ],
            handler: static function (array $args, \Attractor\Agent\ExecutionEnvironment $env): array {
                $path = (string) $args['path'];
                $old = (string) $args['old_string'];
                $new = (string) $args['new_string'];
                $replaceAll = (bool) ($args['replace_all'] ?? false);

                $raw = $env->readFile($path, 1, null);
                $contents = implode("\n", array_map(static fn (string $line): string => preg_replace('/^\d+ \| /', '', $line) ?? $line, preg_split('/\R/', $raw) ?: []));
                $count = substr_count($contents, $old);
                if ($count === 0) {
                    return ['error' => 'old_string not found', 'is_error' => true];
                }
                if ($count > 1 && !$replaceAll) {
                    return ['error' => 'old_string has multiple matches; set replace_all=true', 'is_error' => true];
                }

                $updated = $replaceAll ? str_replace($old, $new, $contents) : preg_replace('/' . preg_quote($old, '/') . '/', $new, $contents, 1);
                $env->writeFile($path, (string) $updated);

                return ['ok' => true, 'replacements' => $replaceAll ? $count : 1];
            },
        ));

        $registry->register(new Tool(
            name: 'shell',
            description: 'Execute shell command in working directory',
            parametersSchema: [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string'],
                    'timeout_ms' => ['type' => 'integer'],
                ],
                'required' => ['command'],
            ],
            handler: static function (array $args, \Attractor\Agent\ExecutionEnvironment $env) use ($defaultTimeoutMs): array {
                $timeout = isset($args['timeout_ms']) ? (int) $args['timeout_ms'] : $defaultTimeoutMs;
                $res = $env->execCommand((string) $args['command'], $timeout);

                return [
                    'stdout' => $res->stdout,
                    'stderr' => $res->stderr,
                    'exit_code' => $res->exitCode,
                    'timed_out' => $res->timedOut,
                ];
            },
        ));

        $registry->register(new Tool(
            name: 'grep',
            description: 'Search files by regex pattern',
            parametersSchema: [
                'type' => 'object',
                'properties' => [
                    'pattern' => ['type' => 'string'],
                    'path' => ['type' => 'string'],
                ],
                'required' => ['pattern', 'path'],
            ],
            handler: static function (array $args, \Attractor\Agent\ExecutionEnvironment $env): array {
                $out = $env->grep((string) $args['pattern'], (string) $args['path'], new GrepOptions());
                return ['output' => $out];
            },
        ));

        $registry->register(new Tool(
            name: 'glob',
            description: 'List files by glob pattern',
            parametersSchema: [
                'type' => 'object',
                'properties' => [
                    'pattern' => ['type' => 'string'],
                    'path' => ['type' => 'string'],
                ],
                'required' => ['pattern', 'path'],
            ],
            handler: static function (array $args, \Attractor\Agent\ExecutionEnvironment $env): array {
                return ['matches' => $env->glob((string) $args['pattern'], (string) $args['path'])];
            },
        ));
    }
}
