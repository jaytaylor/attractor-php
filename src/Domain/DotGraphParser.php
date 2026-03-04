<?php

declare(strict_types=1);

namespace AttractorPhp\Domain;

use AttractorPhp\Http\ApiError;

final class DotGraphParser
{
    /**
     * @return array{
     *   nodes:array<string,array<string,mixed>>,
     *   edges:list<array<string,mixed>>,
     *   outgoing:array<string,list<array<string,mixed>>>,
     *   incoming:array<string,int>,
     *   startNodeId:string
     * }
     */
    public function parse(string $dotSource): array
    {
        $source = trim($dotSource);
        if ($source === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'DOT source is empty');
        }

        if (!preg_match('/^\s*digraph\s+[A-Za-z0-9_]+\s*\{([\s\S]*)\}\s*$/', $source, $matches)) {
            throw new ApiError(400, 'BAD_REQUEST', 'DOT graph must be a single digraph');
        }

        $body = (string) ($matches[1] ?? '');
        $statements = $this->splitStatements($body);

        $defaultNodeAttrs = [];
        $nodes = [];
        $edges = [];
        $outgoing = [];
        $incoming = [];
        $order = [];
        $index = 0;

        foreach ($statements as $raw) {
            $statement = trim($raw);
            if ($statement === '') {
                continue;
            }

            if (preg_match('/^node\s*\[(.*)\]$/is', $statement, $defaultNodeMatch) === 1) {
                $defaultNodeAttrs = $this->parseAttrs((string) ($defaultNodeMatch[1] ?? ''));
                continue;
            }

            if (str_contains($statement, '->')) {
                $this->parseEdgeStatement($statement, $defaultNodeAttrs, $nodes, $edges, $outgoing, $incoming, $order, $index);
                continue;
            }

            $this->parseNodeStatement($statement, $defaultNodeAttrs, $nodes, $outgoing, $incoming, $order, $index);
        }

        if ($nodes === []) {
            throw new ApiError(400, 'BAD_REQUEST', 'DOT graph has no runnable nodes');
        }

        $startNodeId = $this->resolveStartNodeId($nodes, $incoming, $order);
        if ($startNodeId === '') {
            throw new ApiError(400, 'BAD_REQUEST', 'unable to resolve start node');
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'outgoing' => $outgoing,
            'incoming' => $incoming,
            'startNodeId' => $startNodeId,
        ];
    }

    /** @return list<string> */
    private function splitStatements(string $body): array
    {
        $chunks = [];
        $buffer = '';
        $inString = false;
        $escaped = false;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $ch = $body[$i];
            $buffer .= $ch;

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }

            if ($ch === ';') {
                $chunks[] = trim(substr($buffer, 0, -1));
                $buffer = '';
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $chunks[] = $tail;
        }

        return $chunks;
    }

    /**
     * @param array<string,string> $defaultNodeAttrs
     * @param array<string,array<string,mixed>> $nodes
     * @param array<string,list<array<string,mixed>>> $outgoing
     * @param array<string,int> $incoming
     * @param array<string,int> $order
     */
    private function parseNodeStatement(
        string $statement,
        array $defaultNodeAttrs,
        array &$nodes,
        array &$outgoing,
        array &$incoming,
        array &$order,
        int &$index
    ): void {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $statement) === 1) {
            return;
        }

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*(?:\[(.*)\])?$/is', $statement, $match) !== 1) {
            return;
        }

        $id = (string) ($match[1] ?? '');
        if ($id === '') {
            return;
        }

        $attrs = $defaultNodeAttrs;
        $inline = trim((string) ($match[2] ?? ''));
        if ($inline !== '') {
            foreach ($this->parseAttrs($inline) as $k => $v) {
                $attrs[$k] = $v;
            }
        }

        $this->ensureNode($id, $attrs, $nodes, $outgoing, $incoming, $order, $index);
    }

    /**
     * @param array<string,string> $defaultNodeAttrs
     * @param array<string,array<string,mixed>> $nodes
     * @param list<array<string,mixed>> $edges
     * @param array<string,list<array<string,mixed>>> $outgoing
     * @param array<string,int> $incoming
     * @param array<string,int> $order
     */
    private function parseEdgeStatement(
        string $statement,
        array $defaultNodeAttrs,
        array &$nodes,
        array &$edges,
        array &$outgoing,
        array &$incoming,
        array &$order,
        int &$index
    ): void {
        $attrText = '';
        $chain = $statement;
        $open = strrpos($statement, '[');
        $close = strrpos($statement, ']');
        if ($open !== false && $close !== false && $close > $open) {
            $attrText = substr($statement, $open + 1, $close - $open - 1);
            $chain = trim(substr($statement, 0, $open));
        }

        $edgeAttrs = $this->parseAttrs($attrText);
        $parts = preg_split('/->/', $chain);
        if (!is_array($parts)) {
            return;
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = trim($part);
            if ($id === '') {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $id, $m) !== 1) {
                continue;
            }
            $ids[] = (string) $m[1];
        }

        if (count($ids) < 2) {
            return;
        }

        for ($i = 0; $i < count($ids) - 1; $i++) {
            $from = $ids[$i];
            $to = $ids[$i + 1];

            $this->ensureNode($from, $defaultNodeAttrs, $nodes, $outgoing, $incoming, $order, $index);
            $this->ensureNode($to, $defaultNodeAttrs, $nodes, $outgoing, $incoming, $order, $index);

            $edge = [
                'from' => $from,
                'to' => $to,
                'label' => (string) ($edgeAttrs['label'] ?? ''),
                'attrs' => $edgeAttrs,
            ];

            $edges[] = $edge;
            $outgoing[$from][] = $edge;
            $incoming[$to] = (int) ($incoming[$to] ?? 0) + 1;
        }
    }

    /**
     * @param array<string,string> $attrs
     * @param array<string,array<string,mixed>> $nodes
     * @param array<string,list<array<string,mixed>>> $outgoing
     * @param array<string,int> $incoming
     * @param array<string,int> $order
     */
    private function ensureNode(
        string $id,
        array $attrs,
        array &$nodes,
        array &$outgoing,
        array &$incoming,
        array &$order,
        int &$index
    ): void {
        if (!isset($nodes[$id])) {
            $nodes[$id] = [
                'id' => $id,
                'label' => (string) ($attrs['label'] ?? $id),
                'shape' => (string) ($attrs['shape'] ?? 'box'),
                'attrs' => $attrs,
            ];
            $outgoing[$id] = $outgoing[$id] ?? [];
            $incoming[$id] = $incoming[$id] ?? 0;
            $order[$id] = $index++;
            return;
        }

        $merged = (array) ($nodes[$id]['attrs'] ?? []);
        foreach ($attrs as $k => $v) {
            $merged[$k] = $v;
        }
        $nodes[$id]['attrs'] = $merged;
        $nodes[$id]['label'] = (string) ($merged['label'] ?? $id);
        $nodes[$id]['shape'] = (string) ($merged['shape'] ?? $nodes[$id]['shape'] ?? 'box');
    }

    /** @return array<string,string> */
    private function parseAttrs(string $attrText): array
    {
        $text = trim($attrText);
        if ($text === '') {
            return [];
        }

        $attrs = [];
        $parts = [];
        $buffer = '';
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];
            if ($inString) {
                $buffer .= $ch;
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ',') {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $parts[] = $tail;
        }

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $key = strtolower(trim(substr($part, 0, $eq)));
            $value = trim(substr($part, $eq + 1));
            if ($key === '') {
                continue;
            }
            $attrs[$key] = $this->normalizeAttrValue($value);
        }

        return $attrs;
    }

    private function normalizeAttrValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (strlen($trimmed) >= 2 && $trimmed[0] === '"' && $trimmed[strlen($trimmed) - 1] === '"') {
            $inner = substr($trimmed, 1, -1);
            $inner = str_replace(['\\"', '\\n', '\\t', '\\\\'], ['"', "\n", "\t", '\\'], $inner);
            return $inner;
        }

        return trim($trimmed, '"');
    }

    /**
     * @param array<string,array<string,mixed>> $nodes
     * @param array<string,int> $incoming
     * @param array<string,int> $order
     */
    private function resolveStartNodeId(array $nodes, array $incoming, array $order): string
    {
        foreach ($nodes as $id => $node) {
            $shape = strtolower((string) ($node['shape'] ?? ''));
            if ($shape === 'mdiamond') {
                return $id;
            }
        }

        foreach (['start', 'Start'] as $candidate) {
            if (isset($nodes[$candidate])) {
                return $candidate;
            }
        }

        $roots = [];
        foreach ($nodes as $id => $_node) {
            if ((int) ($incoming[$id] ?? 0) === 0) {
                $roots[] = $id;
            }
        }
        if ($roots !== []) {
            usort($roots, static fn(string $a, string $b): int => ($order[$a] ?? 0) <=> ($order[$b] ?? 0));
            return $roots[0];
        }

        $ids = array_keys($nodes);
        usort($ids, static fn(string $a, string $b): int => ($order[$a] ?? 0) <=> ($order[$b] ?? 0));
        return $ids[0] ?? '';
    }
}

