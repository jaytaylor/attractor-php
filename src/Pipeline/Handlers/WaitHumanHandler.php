<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Handlers;

use Attractor\Pipeline\Handler;
use Attractor\Pipeline\Interviewer;
use Attractor\Pipeline\Model\Graph;
use Attractor\Pipeline\Model\Node;
use Attractor\Pipeline\Question;
use Attractor\Pipeline\Runtime\Context;
use Attractor\Pipeline\Runtime\Outcome;

final class WaitHumanHandler implements Handler
{
    public function __construct(private readonly Interviewer $interviewer)
    {
    }

    public function execute(Node $node, Context $context, Graph $graph, string $logsRoot): Outcome
    {
        $options = array_map(static fn ($edge): string => $edge->label(), $graph->outgoing($node->id));
        $options = array_values(array_filter($options, static fn (string $label): bool => trim($label) !== ''));

        $answer = $this->interviewer->ask(new Question(
            type: 'SINGLE_SELECT',
            prompt: (string) $node->attr('question', 'Choose next step'),
            options: $options,
        ));

        $selected = $answer->selected[0] ?? null;

        return Outcome::success('human decision', $selected);
    }
}
