<?php

declare(strict_types=1);

namespace App\Services;

final class DotService
{
    /**
     * @return array{valid:bool, diagnostics:list<array<string,mixed>>}
     */
    public function validate(string $dot): array
    {
        $diagnostics = [];

        if (trim($dot) === '') {
            $diagnostics[] = ['severity' => 'error', 'message' => 'DOT source is empty'];
        }

        if (!str_contains($dot, 'digraph')) {
            $diagnostics[] = ['severity' => 'error', 'message' => 'DOT must start with digraph'];
        }

        $open = substr_count($dot, '{');
        $close = substr_count($dot, '}');
        if ($open !== $close) {
            $diagnostics[] = ['severity' => 'error', 'message' => 'Unbalanced braces in DOT'];
        }

        if (!preg_match('/[A-Za-z0-9_]+\s*->\s*[A-Za-z0-9_]+/', $dot)) {
            $diagnostics[] = ['severity' => 'error', 'message' => 'DOT must include at least one edge'];
        }

        return [
            'valid' => count($diagnostics) === 0,
            'diagnostics' => $diagnostics,
        ];
    }

    public function renderSvg(string $dot): string
    {
        $escaped = htmlspecialchars($dot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<svg xmlns="http://www.w3.org/2000/svg" width="960" height="360">'
            . '<rect width="100%" height="100%" fill="#f7fafc"/>'
            . '<text x="20" y="34" font-size="20" font-family="Courier New, monospace" fill="#1f2937">DOT Preview</text>'
            . '<foreignObject x="20" y="50" width="920" height="290">'
            . '<div xmlns="http://www.w3.org/1999/xhtml" style="font-family:Courier New,monospace;font-size:13px;white-space:pre-wrap;color:#111827;">'
            . $escaped
            . '</div></foreignObject></svg>';
    }

    public function generate(string $prompt): string
    {
        $label = $this->slugWords($prompt);
        return "digraph Pipeline {\n  start -> plan;\n  plan -> build;\n  build -> review;\n  review -> done;\n  plan [label=\"Plan {$label}\"];\n  done [shape=Msquare];\n}";
    }

    public function fix(string $dot): string
    {
        $clean = $this->stripCodeFences($dot);
        if (!str_contains($clean, 'digraph')) {
            $clean = "digraph Pipeline {\n" . trim($clean) . "\n}";
        }

        if (!str_contains($clean, '->')) {
            $clean = "digraph Pipeline {\n  start -> done;\n  done [shape=Msquare];\n}";
        }

        $open = substr_count($clean, '{');
        $close = substr_count($clean, '}');
        if ($open > $close) {
            $clean .= str_repeat('}', $open - $close);
        }
        if ($close > $open) {
            $clean = "digraph Pipeline {\n  start -> done;\n  done [shape=Msquare];\n}";
        }

        return $clean;
    }

    public function iterate(string $baseDot, string $changes): string
    {
        $base = $this->stripCodeFences($baseDot);
        $changesLabel = $this->slugWords($changes);
        if (!str_contains($base, 'done [shape=Msquare]')) {
            $base = preg_replace('/}\s*$/', "  done [shape=Msquare];\n}", $base);
            if (!is_string($base)) {
                $base = "digraph Pipeline {\n  start -> done;\n  done [shape=Msquare];\n}";
            }
        }

        if (!str_contains($base, 'iterate_step')) {
            $base = preg_replace('/}\s*$/', "  review -> iterate_step;\n  iterate_step -> done;\n  iterate_step [label=\"Iterate {$changesLabel}\"];\n}", $base);
            if (!is_string($base)) {
                $base = "digraph Pipeline {\n  start -> iterate_step;\n  iterate_step -> done;\n  iterate_step [label=\"Iterate {$changesLabel}\"];\n  done [shape=Msquare];\n}";
            }
        }

        return $base;
    }

    /**
     * @return list<string>
     */
    public function extractStages(string $dot): array
    {
        preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\b\s*->\s*\b([A-Za-z_][A-Za-z0-9_]*)\b/', $dot, $matches, PREG_SET_ORDER);
        $nodes = [];
        foreach ($matches as $m) {
            $from = $m[1] ?? '';
            $to = $m[2] ?? '';
            if ($from !== '' && !in_array($from, $nodes, true)) {
                $nodes[] = $from;
            }
            if ($to !== '' && !in_array($to, $nodes, true)) {
                $nodes[] = $to;
            }
        }

        if ($nodes === []) {
            return ['start', 'done'];
        }

        return $nodes;
    }

    /**
     * @return list<string>
     */
    public function streamChunks(string $dot): array
    {
        $chunks = [];
        $offset = 0;
        while ($offset < strlen($dot)) {
            $chunks[] = substr($dot, $offset, 36);
            $offset += 36;
        }

        return $chunks;
    }

    public function stripCodeFences(string $text): string
    {
        $stripped = preg_replace('/```[a-zA-Z]*\n?|```/', '', $text);
        if (!is_string($stripped)) {
            return $text;
        }

        return trim($stripped);
    }

    private function slugWords(string $text): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $text);
        if (!is_string($clean)) {
            return 'Pipeline';
        }

        $parts = preg_split('/\s+/', trim($clean));
        if (!is_array($parts) || $parts === []) {
            return 'Pipeline';
        }

        $parts = array_slice(array_filter($parts, static fn ($p) => is_string($p) && $p !== ''), 0, 4);
        $parts = array_map(static fn (string $p): string => ucfirst(strtolower($p)), $parts);

        return $parts !== [] ? implode(' ', $parts) : 'Pipeline';
    }
}
