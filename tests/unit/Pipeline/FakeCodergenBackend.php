<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\Pipeline;

use Attractor\Pipeline\CodergenBackend;
use Attractor\Pipeline\Model\Node;
use Attractor\Pipeline\Runtime\Context;
use Attractor\Pipeline\Runtime\Outcome;

final class FakeCodergenBackend implements CodergenBackend
{
    /** @var list<string> */
    public array $prompts = [];

    private bool $returnedFailure = false;

    public function __construct(
        private readonly ?Outcome $fixedOutcome = null,
        private readonly bool $failFirstOnly = false,
    )
    {
    }

    public function run(Node $node, string $prompt, Context $context)
    {
        $this->prompts[] = $prompt;
        if ($this->fixedOutcome !== null) {
            if ($this->failFirstOnly && $this->returnedFailure) {
                return 'generated: ' . $prompt;
            }
            $this->returnedFailure = true;
            return $this->fixedOutcome;
        }

        return 'generated: ' . $prompt;
    }
}
