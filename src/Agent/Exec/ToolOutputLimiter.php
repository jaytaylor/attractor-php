<?php

declare(strict_types=1);

namespace Attractor\Agent\Exec;

final class ToolOutputLimiter
{
    public static function truncate(string $output, int $maxChars, ?int $maxLines = null): string
    {
        $originalLength = strlen($output);
        if ($originalLength > $maxChars) {
            $removed = $originalLength - $maxChars;
            $head = substr($output, 0, (int) floor($maxChars * 0.6));
            $tail = substr($output, -1 * (int) floor($maxChars * 0.35));
            $output = $head . "\n[WARNING: Tool output was truncated. {$removed} characters removed.]\n" . $tail;
        }

        if ($maxLines !== null) {
            $lines = preg_split('/\R/', $output) ?: [];
            if (count($lines) > $maxLines) {
                $removedLines = count($lines) - $maxLines;
                $headCount = (int) floor($maxLines * 0.6);
                $tailCount = max(1, $maxLines - $headCount - 1);
                $lines = array_merge(
                    array_slice($lines, 0, $headCount),
                    ["[WARNING: Tool output was truncated. {$removedLines} lines removed.]"],
                    array_slice($lines, -1 * $tailCount),
                );
                $output = implode("\n", $lines);
            }
        }

        return $output;
    }
}
