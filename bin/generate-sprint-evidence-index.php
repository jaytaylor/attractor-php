<?php

declare(strict_types=1);

$docPath = $argv[1] ?? 'docs/sprints/SPRINT-002-attractor-php-web-dashboard.md';
$outPath = $argv[2] ?? '.scratch/verification/SPRINT-002/index.md';

if (!is_file($docPath)) {
    fwrite(STDERR, "missing sprint doc: {$docPath}\n");
    exit(2);
}

$lines = file($docPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "failed reading sprint doc: {$docPath}\n");
    exit(2);
}

$items = [];
$lineCount = count($lines);
for ($i = 0; $i < $lineCount; $i++) {
    $line = $lines[$i];
    if (!preg_match('/^- \[([ X])\] (.+)$/', $line, $matches)) {
        continue;
    }

    $status = $matches[1] === 'X' ? 'X' : ' ';
    $title = trim($matches[2]);

    $id = '';
    if (preg_match('/^([A-Z0-9-]+(?:\.[0-9]+)?):?\s+/', $title, $idMatch) === 1) {
        $id = $idMatch[1];
    }
    if ($id === '') {
        $id = 'L' . ($i + 1);
    }

    $commands = [];
    $evidence = [];

    $foundFenceStart = false;
    for ($j = $i + 1; $j < $lineCount; $j++) {
        $candidate = $lines[$j];
        if (!$foundFenceStart) {
            if (preg_match('/^```/', $candidate) === 1) {
                $foundFenceStart = true;
            }
            if (preg_match('/^- \[[ X]\] /', $candidate) === 1 || preg_match('/^#{1,6} /', $candidate) === 1) {
                break;
            }
            continue;
        }

        if (preg_match('/^```/', $candidate) === 1) {
            break;
        }

        if (preg_match('/^-\s+(.+exit\s+[0-9]+.*)$/', trim($candidate), $cmdMatch) === 1) {
            $commands[] = trim($cmdMatch[1]);
        }
        if (preg_match('#\.scratch/[A-Za-z0-9_./-]+#', $candidate, $pathMatch) === 1) {
            $evidence[] = $pathMatch[0];
        }
    }

    $items[] = [
        'id' => $id,
        'status' => $status,
        'title' => $title,
        'commands' => array_values(array_unique($commands)),
        'evidence' => array_values(array_unique($evidence)),
    ];
}

$checked = count(array_filter($items, static fn(array $item): bool => $item['status'] === 'X'));
$total = count($items);

$dir = dirname($outPath);
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    fwrite(STDERR, "failed creating output dir: {$dir}\n");
    exit(2);
}

$linesOut = [];
$linesOut[] = '# Sprint 002 Evidence Index';
$linesOut[] = '';
$linesOut[] = '- Source: `' . $docPath . '`';
$linesOut[] = '- Generated at: ' . gmdate('c');
$linesOut[] = '- Checklist completion: ' . $checked . '/' . $total;
$linesOut[] = '';
$linesOut[] = '| ID | Status | Checklist Item | Commands (with exit) | Evidence Artifacts |';
$linesOut[] = '|---|---|---|---|---|';

foreach ($items as $item) {
    $commands = $item['commands'] === [] ? '-' : implode('<br>', array_map(static fn(string $v): string => '`' . str_replace('`', '\\`', $v) . '`', $item['commands']));
    $evidence = $item['evidence'] === [] ? '-' : implode('<br>', array_map(static fn(string $v): string => '`' . str_replace('`', '\\`', $v) . '`', $item['evidence']));
    $title = str_replace('|', '\\|', $item['title']);
    $linesOut[] = sprintf('| %s | %s | %s | %s | %s |', $item['id'], $item['status'], $title, $commands, $evidence);
}

$content = implode("\n", $linesOut) . "\n";
if (file_put_contents($outPath, $content) === false) {
    fwrite(STDERR, "failed writing output: {$outPath}\n");
    exit(2);
}

fwrite(STDOUT, "generated={$outPath}\n");
fwrite(STDOUT, "items={$total}\n");
exit(0);
