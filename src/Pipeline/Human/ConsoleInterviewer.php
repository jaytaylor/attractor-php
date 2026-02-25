<?php

declare(strict_types=1);

namespace Attractor\Pipeline\Human;

use Attractor\Pipeline\Answer;
use Attractor\Pipeline\Interviewer;
use Attractor\Pipeline\Question;

final class ConsoleInterviewer implements Interviewer
{
    public function ask(Question $question): Answer
    {
        fwrite(STDOUT, $question->prompt . PHP_EOL);
        foreach ($question->options as $idx => $option) {
            fwrite(STDOUT, sprintf("%d) %s\n", $idx + 1, $option));
        }
        $input = trim((string) fgets(STDIN));

        if (is_numeric($input)) {
            $index = ((int) $input) - 1;
            if (isset($question->options[$index])) {
                return new Answer(selected: [$question->options[$index]]);
            }
        }

        if ($input !== '') {
            return new Answer(text: $input, selected: [$input]);
        }

        return new Answer();
    }

    public function askMultiple(array $questions): array
    {
        return array_map(fn (Question $q): Answer => $this->ask($q), $questions);
    }

    public function inform(string $message, string $stage): void
    {
        fwrite(STDOUT, "[{$stage}] {$message}" . PHP_EOL);
    }
}
