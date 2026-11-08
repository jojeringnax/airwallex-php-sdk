<?php

declare(strict_types=1);

function copyDirectory(string $source, string $destination): void
{
    if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException('Cannot create consumer fixture directory.');
    }
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot() || $entry->isLink()) {
            continue;
        }
        $target = $destination . '/' . $entry->getFilename();
        if ($entry->isDir()) {
            copyDirectory($entry->getPathname(), $target);
        } elseif (!copy($entry->getPathname(), $target)) {
            throw new RuntimeException('Cannot copy package source.');
        }
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        if ($entry->isDir() && !$entry->isLink()) {
            removeDirectory($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

/** @param list<string> $command */
function runCommand(array $command, string $directory): void
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Consumer fixture command failed.');
    }
}
