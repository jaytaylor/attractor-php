<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Model;

final class Graph
{
    /**
     * @param array<string, string> $attrs
     * @param array<string, Node> $nodes
     * @param list<Edge> $edges
     */
    public function __construct(
        public array $attrs = [],
        public array $nodes = [],
        public array $edges = [],
    ) {
    }

    public function addNode(Node $node): void
    {
        $this->nodes[$node->id] = $node;
    }

    public function addEdge(Edge $edge): void
    {
        $this->edges[] = $edge;
    }

    public function goal(): string
    {
        return (string) ($this->attrs['goal'] ?? '');
    }

    public function startNode(): ?Node
    {
        foreach ($this->nodes as $node) {
            if ($node->shape() === 'Mdiamond') {
                return $node;
            }
        }

        return $this->nodes['start'] ?? $this->nodes['Start'] ?? null;
    }

    /** @return list<Node> */
    public function exitNodes(): array
    {
        $out = [];
        foreach ($this->nodes as $node) {
            if ($node->shape() === 'Msquare' || preg_match('/^(exit|end)$/i', $node->id) === 1) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /** @return list<Edge> */
    public function outgoing(string $nodeId): array
    {
        return array_values(array_filter($this->edges, static fn (Edge $edge): bool => $edge->from === $nodeId));
    }

    /** @return list<Edge> */
    public function incoming(string $nodeId): array
    {
        return array_values(array_filter($this->edges, static fn (Edge $edge): bool => $edge->to === $nodeId));
    }
}
