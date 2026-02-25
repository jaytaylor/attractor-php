<?php

declare(strict_types=1);

namespace Attractor\Agent;

final class ProjectDocs
{
    /** @param list<string> $documents */
    public function __construct(
        public readonly array $documents,
    ) {
    }

    public static function discover(string $workingDirectory, string $providerId, int $maxBytes = 32768): self
    {
        $candidates = ['AGENTS.md'];
        if ($providerId === 'anthropic') {
            $candidates[] = 'CLAUDE.md';
        }
        if ($providerId === 'gemini') {
            $candidates[] = 'GEMINI.md';
        }
        if ($providerId === 'openai') {
            $candidates[] = 'OPENAI.md';
        }

        $found = [];
        $usedBytes = 0;
        foreach ($candidates as $name) {
            $path = rtrim($workingDirectory, '/') . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $content = (string) file_get_contents($path);
            if ($content === '') {
                continue;
            }

            $remaining = $maxBytes - $usedBytes;
            if ($remaining <= 0) {
                break;
            }

            $snippet = substr($content, 0, $remaining);
            if (strlen($snippet) < strlen($content)) {
                $snippet .= "\n[TRUNCATED: document budget exceeded]";
            }
            $found[] = "# {$name}\n" . $snippet;
            $usedBytes += strlen($snippet);
        }

        return new self($found);
    }

    public function asPromptBlock(): string
    {
        if ($this->documents === []) {
            return '';
        }

        return implode("\n\n", $this->documents);
    }
}
