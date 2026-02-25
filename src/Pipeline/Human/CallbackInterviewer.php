<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Human;

use Attractor\Pipeline\Answer;
use Attractor\Pipeline\Interviewer;
use Attractor\Pipeline\Question;

final class CallbackInterviewer implements Interviewer
{
    /** @param callable(Question): Answer $callback */
    public function __construct(private readonly \Closure $callback)
    {
    }

    public function ask(Question $question): Answer
    {
        return ($this->callback)($question);
    }

    public function askMultiple(array $questions): array
    {
        return array_map(fn (Question $q): Answer => $this->ask($q), $questions);
    }

    public function inform(string $message, string $stage): void
    {
    }
}
