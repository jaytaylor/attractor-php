#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "usage: run-worker.php <project-root> <run-id>\n");
    exit(1);
}

$projectRoot = (string) $argv[1];
$runId = (string) $argv[2];

require_once $projectRoot . '/src/bootstrap.php';

$dotService = new App\Services\DotService();
$runs = new App\Services\RunRepository($projectRoot, $dotService);
$runs->processRun($runId);
