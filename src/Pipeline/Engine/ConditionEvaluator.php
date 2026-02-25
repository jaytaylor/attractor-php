<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Engine;

use Attractor\Pipeline\Runtime\Context;
use Attractor\Pipeline\Runtime\Outcome;

final class ConditionEvaluator
{
    public static function validate(string $condition): void
    {
        if ($condition === '') {
            return;
        }

        foreach (explode('&&', $condition) as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }
            if (!str_contains($clause, '=') && !str_contains($clause, '!=')) {
                throw new \InvalidArgumentException('unsupported clause: ' . $clause);
            }
        }
    }

    public static function evaluate(string $condition, Outcome $outcome, Context $context): bool
    {
        if (trim($condition) === '') {
            return true;
        }

        $clauses = explode('&&', $condition);
        foreach ($clauses as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }

            $ok = false;
            if (str_contains($clause, '!=')) {
                [$left, $right] = array_map('trim', explode('!=', $clause, 2));
                $ok = self::resolve($left, $outcome, $context) !== trim($right, "\"'");
            } elseif (str_contains($clause, '=')) {
                [$left, $right] = array_map('trim', explode('=', $clause, 2));
                $ok = self::resolve($left, $outcome, $context) === trim($right, "\"'");
            }

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private static function resolve(string $expr, Outcome $outcome, Context $context): string
    {
        return match (true) {
            $expr === 'outcome' => $outcome->status,
            $expr === 'preferred_label' => $outcome->preferredLabel ?? '',
            str_starts_with($expr, 'context.') => (string) $context->get(substr($expr, 8), ''),
            default => trim($expr, "\"'"),
        };
    }
}
