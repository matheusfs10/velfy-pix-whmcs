<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;
foreach (['modules', 'tests', 'dev', 'scripts'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') continue;
        passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $result);
        $failed = $failed || $result !== 0;
    }
}
exit($failed ? 1 : 0);

