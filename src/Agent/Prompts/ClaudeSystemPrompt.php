<?php

declare(strict_types=1);

namespace App\Agent\Prompts;

final class ClaudeSystemPrompt
{
    /**
     * @var array<string,string>
     */
    private static array $constantCache = [];

    public static function buildPrompt(
        ClaudePromptEnvironment $environment,
        bool $enableTodos = true,
        string $modelName = 'Claude',
        string $modelId = 'claude-sonnet-4-6',
        array $availableSkills = [],
        ?array $allowedTools = null,
    ): string {
        $sections = [];
        $sections[] = self::buildToolDescriptions($allowedTools);
        $sections[] = self::constant('basePrompt');

        if ($enableTodos) {
            $sections[] = self::constant('taskManagementSection');
        }

        $sections[] = self::constant('askingQuestionsSection');
        $sections[] = self::constant('doingTasksSection');
        $sections[] = self::constant('toolUsagePolicy');

        if ($availableSkills !== []) {
            $sections[] = self::buildSkillsSection($availableSkills);
        }

        $sections[] = self::constant('gitCommitSection');
        $sections[] = self::constant('prSection');
        $sections[] = self::buildEnvironmentSection($environment, $modelName, $modelId);

        if ($environment->gitInfo !== null) {
            $sections[] = self::buildGitSection($environment->gitInfo);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return list<string>
     */
    public static function allToolNames(): array
    {
        return [
            'Task', 'Bash', 'Glob', 'Grep', 'Read', 'Edit', 'Write',
            'NotebookEdit', 'WebFetch', 'WebSearch', 'TodoWrite',
            'AskUserQuestion', 'ExitPlanMode', 'EnterPlanMode',
            'TaskStop', 'TaskOutput', 'Skill', 'SendMessage',
            'TaskCreate', 'TaskGet', 'TaskList', 'TaskUpdate',
            'TeamCreate', 'TeamDelete', 'ToolSearch', 'KillShell',
        ];
    }

    public static function toolDescriptions(): string
    {
        return self::buildToolDescriptions();
    }

    public static function buildToolDescriptions(?array $allowedTools = null): string
    {
        $tools = $allowedTools ?? self::allToolNames();
        $toolSet = array_fill_keys($tools, true);

        $sections = [];
        $sections[] = "In this environment you have access to a set of tools you can use to answer the user's question.\n\n"
            . "You can invoke functions by writing a function call block as part of your reply to the user.\n\n"
            . 'String and scalar parameters should be specified as is, while lists and objects should use JSON format.';

        if (isset($toolSet['Task'])) {
            $sections[] = self::constant('toolDescriptionTask');
        }
        if (isset($toolSet['Bash'])) {
            $sections[] = self::constant('toolDescriptionBash');
        }
        if (isset($toolSet['Glob'])) {
            $sections[] = self::constant('toolDescriptionGlob');
        }
        if (isset($toolSet['Grep'])) {
            $sections[] = self::constant('toolDescriptionGrep');
        }
        if (isset($toolSet['Read'])) {
            $sections[] = self::constant('toolDescriptionRead');
        }
        if (isset($toolSet['Edit'])) {
            $sections[] = self::constant('toolDescriptionEdit');
        }
        if (isset($toolSet['Write'])) {
            $sections[] = self::constant('toolDescriptionWrite');
        }
        if (isset($toolSet['NotebookEdit'])) {
            $sections[] = self::constant('toolDescriptionNotebookEdit');
        }
        if (isset($toolSet['WebFetch'])) {
            $sections[] = self::constant('toolDescriptionWebFetch');
        }
        if (isset($toolSet['WebSearch'])) {
            $sections[] = self::constant('toolDescriptionWebSearch');
        }
        if (isset($toolSet['TodoWrite'])) {
            $sections[] = self::constant('toolDescriptionTodoWrite');
        }
        if (isset($toolSet['AskUserQuestion'])) {
            $sections[] = self::constant('toolDescriptionAskUserQuestion');
        }
        if (isset($toolSet['ExitPlanMode'])) {
            $sections[] = self::constant('toolDescriptionExitPlanMode');
        }
        if (isset($toolSet['EnterPlanMode'])) {
            $sections[] = self::constant('toolDescriptionEnterPlanMode');
        }
        if (isset($toolSet['TaskStop']) || isset($toolSet['KillShell'])) {
            $sections[] = self::constant('toolDescriptionTaskStop');
        }
        if (isset($toolSet['TaskOutput'])) {
            $sections[] = self::constant('toolDescriptionTaskOutput');
        }
        if (isset($toolSet['Skill'])) {
            $sections[] = self::constant('toolDescriptionSkill');
        }
        if (isset($toolSet['SendMessage'])) {
            $sections[] = self::constant('toolDescriptionSendMessage');
        }
        if (isset($toolSet['TaskCreate'])) {
            $sections[] = self::constant('toolDescriptionTaskCreate');
        }
        if (isset($toolSet['TaskGet'])) {
            $sections[] = self::constant('toolDescriptionTaskGet');
        }
        if (isset($toolSet['TaskList'])) {
            $sections[] = self::constant('toolDescriptionTaskList');
        }
        if (isset($toolSet['TaskUpdate'])) {
            $sections[] = self::constant('toolDescriptionTaskUpdate');
        }
        if (isset($toolSet['TeamCreate'])) {
            $sections[] = self::constant('toolDescriptionTeamCreate');
        }
        if (isset($toolSet['TeamDelete'])) {
            $sections[] = self::constant('toolDescriptionTeamDelete');
        }
        if (isset($toolSet['ToolSearch'])) {
            $sections[] = self::constant('toolDescriptionToolSearch');
        }

        return implode("\n\n", $sections);
    }

    private static function buildSkillsSection(array $skills): string
    {
        usort(
            $skills,
            static function (ClaudePromptSkill $a, ClaudePromptSkill $b): int {
                return $a->name <=> $b->name;
            },
        );

        $lines = [
            '# Available Skills',
            '',
            'The following skills are available in this project. Use the Skill tool to invoke them:',
            '',
        ];

        foreach ($skills as $skill) {
            $lines[] = '- /' . $skill->name . ': ' . $skill->description;
        }

        $lines[] = '';
        $lines[] = 'To invoke a skill, use: Skill(skill: "<skill-name>")';

        return implode("\n", $lines);
    }

    private static function buildEnvironmentSection(
        ClaudePromptEnvironment $environment,
        string $modelName,
        string $modelId,
    ): string {
        $today = date('Y-m-d');
        $osVersion = self::osVersionString();

        return "Here is useful information about the environment you are running in:\n"
            . "<env>\n"
            . 'Working directory: ' . $environment->workingDirectory . "\n"
            . 'Is directory a git repo: ' . ($environment->gitInfo !== null ? 'Yes' : 'No') . "\n"
            . 'Platform: ' . self::platformName() . "\n"
            . 'OS Version: ' . $osVersion . "\n"
            . "Today's date: {$today}\n"
            . "</env>\n"
            . "You are powered by the model named {$modelName}. The exact model ID is {$modelId}.\n\n"
            . 'Assistant knowledge cutoff is May 2025.';
    }

    private static function buildGitSection(ClaudePromptGitInfo $gitInfo): string
    {
        $parts = [];
        $parts[] = 'gitStatus: This is the git status at the start of the conversation. Note that this status is a snapshot in time, and will not update during the conversation.';

        if ($gitInfo->branch !== null && $gitInfo->branch !== '') {
            $parts[] = 'Current branch: ' . $gitInfo->branch;
        }

        $parts[] = 'Main branch (you will usually use this for PRs): main';

        if ($gitInfo->hasUncommittedChanges) {
            $parts[] = 'Status: Has uncommitted changes';
        }

        if ($gitInfo->recentCommits !== null && $gitInfo->recentCommits !== '') {
            $parts[] = "Recent commits:\n" . $gitInfo->recentCommits;
        }

        return implode("\n", $parts);
    }

    private static function platformName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            default => 'unknown',
        };
    }

    private static function osVersionString(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'Darwin ' . php_uname('v'),
            'Linux' => 'Linux',
            default => 'Unknown',
        };
    }

    private static function constant(string $name): string
    {
        if (array_key_exists($name, self::$constantCache)) {
            return self::$constantCache[$name];
        }

        $source = self::swiftSource();
        $pattern = '/(?:public|private)\\s+static\\s+let\\s+'
            . preg_quote($name, '/')
            . '\\s*=\\s*"""(.*?)"""/s';

        if (preg_match($pattern, $source, $matches) !== 1) {
            self::$constantCache[$name] = '';
            return '';
        }

        $text = self::dedentSwiftMultilineString($matches[1]);
        self::$constantCache[$name] = $text;
        return $text;
    }

    private static function swiftSource(): string
    {
        static $source = null;
        if (is_string($source)) {
            return $source;
        }

        $path = dirname(__DIR__, 3) . '/resources/prompts/upstream/ClaudeSystemPrompt.swift';
        $content = @file_get_contents($path);
        $source = is_string($content) ? str_replace("\r\n", "\n", $content) : '';
        return $source;
    }

    private static function dedentSwiftMultilineString(string $raw): string
    {
        $raw = str_replace("\r\n", "\n", $raw);
        if (str_starts_with($raw, "\n")) {
            $raw = substr($raw, 1);
        }

        $lines = explode("\n", $raw);
        $minIndent = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            preg_match('/^[ \t]*/', $line, $matches);
            $indent = strlen($matches[0] ?? '');
            if ($minIndent === null || $indent < $minIndent) {
                $minIndent = $indent;
            }
        }

        if (($minIndent ?? 0) > 0) {
            foreach ($lines as $i => $line) {
                if ($line === '') {
                    continue;
                }
                $lines[$i] = preg_replace('/^[ \t]{0,' . $minIndent . '}/', '', $line, 1) ?? $line;
            }
        }

        return implode("\n", $lines);
    }
}
