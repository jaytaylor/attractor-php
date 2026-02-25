<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Stylesheet;

use Attractor\Pipeline\Model\Graph;

final class ModelStylesheet
{
    public static function apply(Graph $graph): void
    {
        $raw = $graph->attrs['model_stylesheet'] ?? '';
        if ($raw === '') {
            return;
        }

        $rules = self::parse($raw);
        foreach ($graph->nodes as $node) {
            $computed = [];
            foreach ($rules as $rule) {
                if (!self::matches($rule['selector'], $node->id, $node->shape(), $node->attrs['class'] ?? '')) {
                    continue;
                }
                $computed = array_merge($computed, $rule['attrs']);
            }

            foreach ($computed as $k => $v) {
                if (!isset($node->attrs[$k])) {
                    $node->attrs[$k] = $v;
                }
            }
        }
    }

    /**
     * @return list<array{selector:string,attrs:array<string,string>}>
     */
    private static function parse(string $raw): array
    {
        $rules = [];
        if (preg_match_all('/([^{}]+)\{([^{}]+)\}/', $raw, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $m) {
                $selector = trim($m[1]);
                $attrs = [];
                if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*=\s*"?([^;"\\n]+)"?\s*;?/', $m[2], $am, PREG_SET_ORDER) !== false) {
                    foreach ($am as $attrMatch) {
                        $attrs[$attrMatch[1]] = trim($attrMatch[2]);
                    }
                }
                $rules[] = ['selector' => $selector, 'attrs' => $attrs];
            }
        }

        usort($rules, static function (array $a, array $b): int {
            return self::specificity($a['selector']) <=> self::specificity($b['selector']);
        });

        return $rules;
    }

    private static function specificity(string $selector): int
    {
        if (str_starts_with($selector, '#')) {
            return 30;
        }
        if (str_starts_with($selector, '.')) {
            return 20;
        }
        if ($selector === '*') {
            return 0;
        }

        return 10;
    }

    private static function matches(string $selector, string $id, string $shape, string $class): bool
    {
        if ($selector === '*') {
            return true;
        }
        if (str_starts_with($selector, '#')) {
            return substr($selector, 1) === $id;
        }
        if (str_starts_with($selector, '.')) {
            $classes = preg_split('/\s+/', trim($class)) ?: [];
            return in_array(substr($selector, 1), $classes, true);
        }

        return $selector === $shape;
    }
}
