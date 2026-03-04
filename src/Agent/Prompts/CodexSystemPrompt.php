<?php

declare(strict_types=1);

namespace App\Agent\Prompts;

final class CodexSystemPrompt
{
    /**
     * @var array<string,string>
     */
    private static array $promptCache = [];

    public static function basePrompt(): string
    {
        return self::loadPrompt('prompt.md');
    }

    public static function codexModelPrompt(): string
    {
        return self::loadPrompt('gpt_5_codex_prompt.md');
    }

    public static function gpt52Prompt(): string
    {
        return self::loadPrompt('gpt_5_2_prompt.md');
    }

    public static function gpt51Prompt(): string
    {
        return self::loadPrompt('gpt_5_1_prompt.md');
    }

    public static function gpt51CodexMaxPrompt(): string
    {
        return self::loadPrompt('gpt-5.1-codex-max_prompt.md');
    }

    public static function applyPatchInstructions(): string
    {
        return self::loadPrompt('apply_patch_tool_instructions.md');
    }

    public static function fullPrompt(): string
    {
        return self::basePrompt() . self::applyPatchInstructions();
    }

    public static function promptFor(string $modelId): string
    {
        $id = strtolower($modelId);

        if (str_contains($id, 'gpt-5.1-codex-max') || str_contains($id, 'gpt5.1-codex-max')) {
            return self::gpt51CodexMaxPrompt() . self::applyPatchInstructions();
        }

        if (str_contains($id, 'codex')) {
            return self::codexModelPrompt() . self::applyPatchInstructions();
        }

        if (str_contains($id, 'gpt-5.2') || str_contains($id, 'gpt5.2')) {
            return self::gpt52Prompt() . self::applyPatchInstructions();
        }

        if (str_contains($id, 'gpt-5.1') || str_contains($id, 'gpt5.1')) {
            return self::gpt51Prompt() . self::applyPatchInstructions();
        }

        return self::fullPrompt();
    }

    private static function loadPrompt(string $filename): string
    {
        if (array_key_exists($filename, self::$promptCache)) {
            return self::$promptCache[$filename];
        }

        $path = self::resourceRoot() . '/' . $filename;
        $content = @file_get_contents($path);
        self::$promptCache[$filename] = is_string($content) ? $content : '';
        return self::$promptCache[$filename];
    }

    private static function resourceRoot(): string
    {
        return dirname(__DIR__, 3) . '/resources/prompts/codex';
    }
}
