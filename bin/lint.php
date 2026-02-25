<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$targets = ["$root/src", "$root/tests", "$root/bin"];
$ok = true;

foreach ($targets as $target) {
    if (!is_dir($target)) {
        continue;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $cmd = sprintf('php -l %s', escapeshellarg($file->getPathname()));
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            $ok = false;
            fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        }
        $output = [];
    }
}

exit($ok ? 0 : 1);
