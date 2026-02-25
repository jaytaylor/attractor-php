<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Human;

use Attractor\Pipeline\Answer;
use Attractor\Pipeline\Interviewer;
use Attractor\Pipeline\Question;

final class QueueInterviewer implements Interviewer
{
    /** @param list<Answer> $answers */
    public function __construct(private array $answers)
    {
    }

    public function ask(Question $question): Answer
    {
        if ($this->answers === []) {
            return new Answer();
        }

        return array_shift($this->answers);
    }

    public function askMultiple(array $questions): array
    {
        return array_map(fn (Question $q): Answer => $this->ask($q), $questions);
    }

    public function inform(string $message, string $stage): void
    {
    }
}
