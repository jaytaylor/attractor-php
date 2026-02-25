<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Runtime;

final class Checkpoint
{
    /**
     * @param array<string, int> $retryCounts
     * @param list<string> $completedNodes
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $currentNode,
        public readonly array $completedNodes,
        public readonly array $context,
        public readonly array $retryCounts,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'current_node' => $this->currentNode,
            'completed_nodes' => $this->completedNodes,
            'context' => $this->context,
            'retry_counts' => $this->retryCounts,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            currentNode: (string) $data['current_node'],
            completedNodes: is_array($data['completed_nodes']) ? $data['completed_nodes'] : [],
            context: is_array($data['context']) ? $data['context'] : [],
            retryCounts: is_array($data['retry_counts']) ? $data['retry_counts'] : [],
        );
    }
}
