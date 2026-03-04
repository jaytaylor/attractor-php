<?php

declare(strict_types=1);

namespace AttractorPhp\Domain;

final class DotService
{
    /** @return array{valid:bool,diagnostics:list<array<string,mixed>>,dotSource:string} */
    public function validate(string $dotSource): array
    {
        $clean = $this->stripMarkdownFences($dotSource);
        $diagnostics = [];

        if (trim($clean) === '') {
            $diagnostics[] = ['message' => 'DOT source is required'];
        }

        if (!preg_match('/^\s*digraph\s+[A-Za-z0-9_]+\s*\{[\s\S]*\}\s*$/', $clean)) {
            $diagnostics[] = ['message' => 'DOT must start with digraph NAME { ... }'];
        }

        $open = substr_count($clean, '{');
        $close = substr_count($clean, '}');
        if ($open !== $close) {
            $diagnostics[] = ['message' => 'Unbalanced braces in DOT source'];
        }

        if (str_contains($clean, '-> }') || preg_match('/->\s*;/', $clean)) {
            $diagnostics[] = ['message' => 'Invalid edge target detected'];
        }

        return [
            'valid' => count($diagnostics) === 0,
            'diagnostics' => $diagnostics,
            'dotSource' => $clean,
        ];
    }

    public function render(string $dotSource): string
    {
        $clean = $this->stripMarkdownFences($dotSource);
        $escaped = htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1100" height="700" viewBox="0 0 1100 700" role="img" aria-label="Rendered DOT preview">
  <rect x="0" y="0" width="1100" height="700" fill="#0b1220" />
  <rect x="20" y="20" width="1060" height="660" fill="#111a2d" stroke="#4b5d85" stroke-width="2" rx="12" />
  <text x="42" y="64" fill="#8ad4ff" font-family="monospace" font-size="18">DOT Preview (server-rendered)</text>
  <foreignObject x="42" y="90" width="1018" height="560">
    <div xmlns="http://www.w3.org/1999/xhtml" style="font-family:monospace;color:#dbe7ff;white-space:pre-wrap;font-size:14px;line-height:1.35;">{$escaped}</div>
  </foreignObject>
</svg>
SVG;
    }

    public function generateFromPrompt(string $prompt): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '_', strtolower(trim($prompt)));
        $name = $name === '' ? 'pipeline' : trim((string) $name, '_');
        return "digraph {$name} {\n  start -> plan;\n  plan -> implement;\n  implement -> test;\n  test -> exit;\n}\n";
    }

    public function fixDot(string $dotSource, string $error): string
    {
        $clean = $this->stripMarkdownFences($dotSource);
        $clean = preg_replace('/->\s*;/', '-> fixed_node;', $clean) ?? $clean;
        $clean = str_replace('-> }', '-> fixed_node }', $clean);

        $validated = $this->validate($clean);
        if (!$validated['valid']) {
            return "digraph fixed_pipeline {\n  start -> analyze;\n  analyze -> fixed_node;\n  fixed_node -> exit;\n}\n";
        }

        if ($error !== '' && !str_contains($clean, 'fixed_node')) {
            $clean = preg_replace('/\}\s*$/', "  fixed_node -> exit;\n}\n", $clean) ?? $clean;
        }

        return $clean;
    }

    public function iterateDot(string $baseDot, string $changes): string
    {
        $clean = $this->stripMarkdownFences($baseDot);
        $slug = preg_replace('/[^A-Za-z0-9_]/', '_', strtoupper($changes));
        $slug = trim((string) $slug, '_');
        if ($slug === '') {
            $slug = 'ITERATION';
        }
        $newNode = 'change_' . strtolower(substr($slug, 0, 24));

        if (!preg_match('/\bexit\b/', $clean)) {
            $clean = preg_replace('/\}\s*$/', "  {$newNode} -> done;\n}\n", $clean) ?? $clean;
            return $clean;
        }

        if (preg_match('/([A-Za-z0-9_]+)\s*->\s*exit\s*;/', $clean, $matches) === 1) {
            $source = $matches[1];
            $replacement = "{$source} -> {$newNode};\n  {$newNode} -> exit;";
            $clean = preg_replace('/' . preg_quote($matches[0], '/') . '/', $replacement, $clean, 1) ?? $clean;
        } else {
            $clean = preg_replace('/\}\s*$/', "  {$newNode} -> exit;\n}\n", $clean) ?? $clean;
        }

        return $clean;
    }

    /** @return list<string> */
    public function streamChunks(string $content, int $chunkSize = 48): array
    {
        $chunks = [];
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $chunks[] = substr($content, $offset, $chunkSize);
            $offset += $chunkSize;
        }
        return $chunks;
    }

    public function stripMarkdownFences(string $source): string
    {
        $trimmed = trim($source);
        if (preg_match('/^```(?:dot)?\s*([\s\S]*?)\s*```$/i', $trimmed, $matches) === 1) {
            return trim($matches[1]) . "\n";
        }

        return $source;
    }
}
