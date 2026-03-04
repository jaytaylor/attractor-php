<?php

declare(strict_types=1);

$doc = __DIR__ . '/../docs/sprints/SPRINT-002-attractor-php-web-dashboard.md';
$lines = file($doc, FILE_IGNORE_NEW_LINES);
if (!is_array($lines)) {
    fwrite(STDERR, "Unable to read sprint document\n");
    exit(1);
}

$errors = [];
for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];
    if (preg_match('/^- \[X\] /', $line) === 1) {
        $next = $lines[$i + 1] ?? '';
        if (str_contains($next, '{placeholder for verification justification/reasoning and evidence log}')) {
            $errors[] = 'Completed checkbox with placeholder at line ' . ($i + 2);
        }
    }
}

$content = implode("\n", $lines);
preg_match_all('#\.scratch/verification/SPRINT-002/[A-Za-z0-9_./-]+#', $content, $matches);
foreach ($matches[0] as $path) {
    if (str_contains($path, '...')) {
        continue;
    }
    if (str_ends_with($path, '/')) {
        continue;
    }
    $fullPath = __DIR__ . '/../' . $path;
    if (!file_exists($fullPath)) {
        $errors[] = 'Missing evidence path: ' . $path;
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

echo "Sprint evidence guardrail checks passed\n";
