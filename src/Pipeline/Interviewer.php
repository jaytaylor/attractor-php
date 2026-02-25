<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

interface Interviewer
{
    public function ask(Question $question): Answer;

    /** @param list<Question> $questions @return list<Answer> */
    public function askMultiple(array $questions): array;

    public function inform(string $message, string $stage): void;
}
