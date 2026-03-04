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

        if ($diagnostics === []) {
            $graphviz = $this->graphvizCheck($clean);
            if (!$graphviz['valid']) {
                $diagnostics[] = ['message' => $graphviz['message']];
            }
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
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open('dot -Tsvg', $descriptors, $pipes);
        if (!is_resource($process)) {
            return $this->renderErrorSvg('Graphviz "dot" command is unavailable.');
        }

        fwrite($pipes[0], $clean);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode === 0 && str_contains($stdout, '<svg')) {
            return $stdout;
        }

        $message = trim($stderr);
        if ($message === '') {
            $message = 'dot failed to render the graph.';
        }
        return $this->renderErrorSvg('Graphviz render error: ' . $message);
    }

    private function renderErrorSvg(string $message): string
    {
        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="220" viewBox="0 0 1000 220" role="img" aria-label="Graph preview error">
  <rect x="0" y="0" width="1000" height="220" fill="#1f2937" />
  <rect x="20" y="20" width="960" height="180" fill="#111827" stroke="#ef4444" stroke-width="2" rx="8" />
  <text x="40" y="62" fill="#fca5a5" font-family="monospace" font-size="20">Graph Preview Unavailable</text>
  <text x="40" y="100" fill="#e5e7eb" font-family="monospace" font-size="15">{$escaped}</text>
</svg>
SVG;
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

    public function extractFirstDigraph(string $source): ?string
    {
        $start = stripos($source, 'digraph');
        if ($start === false) {
            return null;
        }

        $open = strpos($source, '{', $start);
        if ($open === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return trim(substr($source, $start, ($i - $start) + 1)) . "\n";
                }
            }
        }

        return null;
    }

    public function fallbackFromPrompt(string $prompt): string
    {
        $normalized = strtolower(trim($prompt));
        $title = trim(preg_replace('/\s+/', ' ', $prompt) ?? '');
        if ($title === '') {
            $title = 'Generated graph';
        }
        $title = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $title) ?? $title;
        $title = str_replace(['\\', '"', '<', '>', '{', '}', '|'], ['/', '\'', '(', ')', '(', ')', '/'], $title);
        if (strlen($title) > 80) {
            $title = substr($title, 0, 77) . '...';
        }

        if (str_contains($normalized, 'dog')) {
            return <<<DOT
digraph generated_pipeline {
  rankdir=LR;
  node [shape=box, style="rounded,filled", fillcolor="#eef6ff", color="#4a6fa5"];
  request [label="Request\\n{$title}"];
  concept [label="Concept sketch"];
  dog_draft [shape=ellipse, fillcolor="#fff3e0", label="Dog draft"];
  validate_initial [shape=diamond, fillcolor="#e8fff2", label="Validate anatomy + style"];
  rework [label="Kick back to concept/implementor"];
  dog_revised [shape=ellipse, fillcolor="#fff3e0", label="Dog revised"];
  validate_final [shape=diamond, fillcolor="#e8fff2", label="Final validation"];
  escalate [label="Escalate for planning review"];
  review_plan [label="Planning adjustment"];
  preview [label="Preview output"];
  request -> concept -> dog_draft -> validate_initial;
  validate_initial -> preview [label="pass"];
  validate_initial -> rework [label="fail"];
  rework -> dog_revised -> validate_final;
  validate_final -> preview [label="pass"];
  validate_final -> escalate [label="fail"];
  escalate -> review_plan -> preview;
}
DOT;
        }

        return <<<DOT
digraph generated_pipeline {
  rankdir=LR;
  node [shape=box, style="rounded,filled", fillcolor="#eef6ff", color="#4a6fa5"];
  request [label="Request\\n{$title}"];
  plan [label="Planning phase"];
  implement [label="Implementation phase"];
  validate_initial [shape=diamond, fillcolor="#e8fff2", label="Validate requirements"];
  rework [label="Kick back to planner/implementor"];
  implement_retry [label="Re-implement changes"];
  validate_final [shape=diamond, fillcolor="#e8fff2", label="Final validation"];
  plan_reset [label="Planning reset"];
  implement_retry2 [label="Implementation retry"];
  validate_escalated [shape=diamond, fillcolor="#e8fff2", label="Escalated validation"];
  escalation [label="Escalate to reviewer"];
  proof [label="Produce validation evidence"];
  request -> plan -> implement -> validate_initial;
  validate_initial -> proof [label="pass"];
  validate_initial -> rework [label="fail"];
  rework -> implement_retry -> validate_final;
  validate_final -> proof [label="pass"];
  validate_final -> plan_reset [label="fail"];
  plan_reset -> implement_retry2 -> validate_escalated;
  validate_escalated -> proof [label="pass"];
  validate_escalated -> escalation [label="fail"];
  escalation -> proof [label="manual decision"];
}
DOT;
    }

    /** @return array{valid:bool,message:string} */
    private function graphvizCheck(string $dotSource): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open('dot -Tsvg', $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['valid' => true, 'message' => ''];
        }

        fwrite($pipes[0], $dotSource);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode === 0 && str_contains($stdout, '<svg')) {
            return ['valid' => true, 'message' => ''];
        }

        $message = trim($stderr);
        if ($message === '') {
            $message = 'DOT failed Graphviz validation';
        }
        return ['valid' => false, 'message' => $message];
    }
}
