<?php

declare(strict_types=1);

namespace Attractor\Agent\Tools;

use Attractor\Agent\ExecutionEnvironment;

final class ApplyPatchV4a
{
    public static function tool(): Tool
    {
        return new Tool(
            name: 'apply_patch',
            description: 'Apply patch in v4a format',
            parametersSchema: [
                'type' => 'object',
                'properties' => [
                    'patch' => ['type' => 'string'],
                ],
                'required' => ['patch'],
            ],
            handler: static function (array $args, ExecutionEnvironment $env): array {
                $patch = (string) ($args['patch'] ?? '');
                if (!str_contains($patch, '*** Begin Patch') || !str_contains($patch, '*** End Patch')) {
                    return ['is_error' => true, 'error' => 'invalid patch format'];
                }

                // Delegate to system apply_patch utility via shell for deterministic behavior.
                $tmp = '.scratch/apply_patch_input.patch';
                $env->writeFile($tmp, $patch);
                $res = $env->execCommand('apply_patch < ' . escapeshellarg($tmp), 20_000);

                return [
                    'exit_code' => $res->exitCode,
                    'stdout' => $res->stdout,
                    'stderr' => $res->stderr,
                    'is_error' => $res->exitCode !== 0,
                ];
            },
        );
    }
}
