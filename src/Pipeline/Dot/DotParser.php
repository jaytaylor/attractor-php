<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Dot;

use Attractor\Pipeline\Model\Edge;
use Attractor\Pipeline\Model\Graph;
use Attractor\Pipeline\Model\Node;
use Attractor\Pipeline\Stylesheet\ModelStylesheet;

final class DotParser
{
    public function parse(string $dotSource): Graph
    {
        $clean = $this->stripComments($dotSource);
        $body = $this->extractDigraphBody($clean);
        $body = $this->flattenSubgraphs($body);

        $graph = new Graph();
        $nodeDefaults = [];
        $edgeDefaults = [];

        foreach ($this->statements($body) as $stmt) {
            if ($stmt === '') {
                continue;
            }

            if (preg_match('/^graph\s*\[(.+)\]$/is', $stmt, $m) === 1) {
                $graph->attrs = array_merge($graph->attrs, $this->parseAttrs($m[1]));
                continue;
            }

            if (preg_match('/^node\s*\[(.+)\]$/is', $stmt, $m) === 1) {
                $nodeDefaults = array_merge($nodeDefaults, $this->parseAttrs($m[1]));
                continue;
            }

            if (preg_match('/^edge\s*\[(.+)\]$/is', $stmt, $m) === 1) {
                $edgeDefaults = array_merge($edgeDefaults, $this->parseAttrs($m[1]));
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]+)\s*\[(.+)\]$/is', $stmt, $m) === 1) {
                $id = $m[1];
                $attrs = array_merge($nodeDefaults, $this->parseAttrs($m[2]));
                $graph->addNode(new Node($id, $attrs));
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]+(?:\s*->\s*[A-Za-z0-9_]+)+)\s*(\[(.+)\])?$/is', $stmt, $m) === 1) {
                $chain = preg_split('/\s*->\s*/', trim($m[1])) ?: [];
                $attrs = array_merge($edgeDefaults, isset($m[3]) ? $this->parseAttrs($m[3]) : []);
                for ($i = 0; $i < count($chain) - 1; $i++) {
                    $graph->addEdge(new Edge($chain[$i], $chain[$i + 1], $attrs));
                }
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]+)$/', $stmt, $m) === 1) {
                $id = $m[1];
                if (!isset($graph->nodes[$id])) {
                    $graph->addNode(new Node($id, $nodeDefaults));
                }
            }
        }

        ModelStylesheet::apply($graph);

        return $graph;
    }

    private function stripComments(string $source): string
    {
        $source = preg_replace('/\/\*.*?\*\//s', '', $source) ?? $source;
        return preg_replace('/\/\/.*$/m', '', $source) ?? $source;
    }

    private function extractDigraphBody(string $source): string
    {
        if (preg_match('/digraph\s+[A-Za-z0-9_]*\s*\{(.*)\}\s*$/is', $source, $m) === 1) {
            return (string) $m[1];
        }

        throw new \InvalidArgumentException('invalid digraph input');
    }

    private function flattenSubgraphs(string $source): string
    {
        $out = $source;
        while (preg_match('/subgraph\s+[A-Za-z0-9_]+\s*\{([^{}]*)\}/is', $out) === 1) {
            $out = preg_replace('/subgraph\s+[A-Za-z0-9_]+\s*\{([^{}]*)\}/is', '$1', $out) ?? $out;
        }

        return $out;
    }

    /** @return list<string> */
    private function statements(string $body): array
    {
        $stmts = [];
        $current = '';
        $depth = 0;
        $inQuote = false;
        $quoteChar = '';
        $escaped = false;
        $chars = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($chars as $ch) {
            if ($escaped) {
                $escaped = false;
            } elseif ($ch === '\\') {
                $escaped = true;
            } elseif (($ch === '"' || $ch === "'") && !$inQuote) {
                $inQuote = true;
                $quoteChar = $ch;
            } elseif ($inQuote && $ch === $quoteChar) {
                $inQuote = false;
                $quoteChar = '';
            }

            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth = max(0, $depth - 1);
            }

            if ($ch === ';' && $depth === 0 && !$inQuote) {
                $stmts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $stmts[] = trim($current);
        }

        return $stmts;
    }

    /** @return array<string, string> */
    private function parseAttrs(string $raw): array
    {
        $attrs = [];
        $pairs = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        $chars = preg_split('//u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($chars as $ch) {
            if (($ch === '"' || $ch === "'") && !$inQuote) {
                $inQuote = true;
                $quoteChar = $ch;
            } elseif ($inQuote && $ch === $quoteChar) {
                $inQuote = false;
                $quoteChar = '';
            }

            if ($ch === ',' && !$inQuote) {
                $pairs[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $pairs[] = trim($current);
        }

        foreach ($pairs as $pair) {
            $eqPos = strpos($pair, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($pair, 0, $eqPos));
            $value = trim(substr($pair, $eqPos + 1));
            if ($key === '') {
                continue;
            }
            $value = trim($value, "\"'");
            $attrs[$key] = str_replace(['\\"', "\\'"], ['"', "'"], $value);
        }

        return $attrs;
    }
}
