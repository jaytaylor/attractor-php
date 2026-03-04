<?php

declare(strict_types=1);

$docPath = $argv[1] ?? 'docs/sprints/SPRINT-002-attractor-php-web-dashboard.md';

if (!is_file($docPath)) {
    fwrite(STDERR, "missing sprint doc: {$docPath}\n");
    exit(2);
}

$lines = file($docPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "failed reading sprint doc: {$docPath}\n");
    exit(2);
}

$checked = 0;
$failures = [];

$lineCount = count($lines);
for ($i = 0; $i < $lineCount; $i++) {
    $line = $lines[$i];
    if (!preg_match('/^- \[X\] (.+)$/', $line, $matches)) {
        continue;
    }

    $checked++;
    $itemTitle = trim($matches[1]);

    $foundFenceStart = false;
    $fence = [];
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

        $fence[] = $candidate;
    }

    $block = implode("\n", $fence);
    $hasVerified = str_contains($block, 'Verified via:');
    $hasExit = preg_match('/exit\s+[0-9]+/', $block) === 1;
    $hasEvidencePath = preg_match('#\.scratch/#', $block) === 1;

    if (!$hasVerified || !$hasExit || !$hasEvidencePath) {
        $failures[] = [
            'line' => $i + 1,
            'item' => $itemTitle,
            'hasVerified' => $hasVerified,
            'hasExit' => $hasExit,
            'hasEvidencePath' => $hasEvidencePath,
        ];
    }
}

if ($failures !== []) {
    fwrite(STDERR, "evidence verification failed for checked sprint items\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, sprintf(
            "- line %d: %s (verified=%s exit=%s evidencePath=%s)\n",
            $failure['line'],
            $failure['item'],
            $failure['hasVerified'] ? 'yes' : 'no',
            $failure['hasExit'] ? 'yes' : 'no',
            $failure['hasEvidencePath'] ? 'yes' : 'no'
        ));
    }
    exit(1);
}

fwrite(STDOUT, "checked_items={$checked}\n");
fwrite(STDOUT, "result=ok\n");
exit(0);
