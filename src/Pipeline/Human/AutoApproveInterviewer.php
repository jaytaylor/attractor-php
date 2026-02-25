<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Human;

use Attractor\Pipeline\Answer;
use Attractor\Pipeline\Interviewer;
use Attractor\Pipeline\Question;

final class AutoApproveInterviewer implements Interviewer
{
    public function ask(Question $question): Answer
    {
        $first = $question->options[0] ?? '';
        return new Answer(selected: $first !== '' ? [$first] : []);
    }

    public function askMultiple(array $questions): array
    {
        return array_map(fn (Question $q): Answer => $this->ask($q), $questions);
    }

    public function inform(string $message, string $stage): void
    {
    }
}
