<?php

declare(strict_types=1);

// Run with: php tests/youtube-duration-cache.php
function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        if (file_exists($path)) {
            unlink($path);
        }
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

$root = tempnam(sys_get_temp_dir(), 'youtube-duration-test-');
if ($root === false || !unlink($root) || !mkdir($root)) {
    fwrite(STDERR, "Could not create temporary test directory\n");
    exit(1);
}

define('DATA_PATH', $root . '/data/cache');
require_once __DIR__ . '/../lib/YouTubeDurationService.php';

$cacheFile = DATA_PATH . '/youtube-duration-cache.json';
$logFile = $root . '/errors.log';
$previousLog = ini_set('error_log', $logFile);
$previousReporting = error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    // The handler still receives warnings suppressed with @ on PHP 8.
    if ((error_reporting() & $severity) !== 0) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return true;
});

$exitCode = 0;
try {
    expect($previousLog !== false, 'Could not configure the test error log');
    $service = new YouTubeDurationService('');

    expect($service->getDurationFromCache('missing') === null, 'A missing cache must return null');
    expect(!file_exists($cacheFile), 'Reading a missing cache must not create it');
    expect(!file_exists($logFile), 'A missing cache must not be logged as a failure');

    // A regular file prevents creation of the nested DATA_PATH directory.
    file_put_contents($root . '/data', 'block directory creation');
    $service->saveDurationsToCache(['first' => 123]);
    clearstatcache();
    expect(!file_exists($cacheFile), 'A failed mkdir must not create a cache');
    expect(is_file($logFile) && trim((string) file_get_contents($logFile)) !== '', 'A failed mkdir must be logged');
    unlink($logFile);
    unlink($root . '/data');

    $service->saveDurationsToCache(['first' => 123, 'replace' => 10]);
    clearstatcache();
    expect(is_file($cacheFile), 'The cache must be created inside DATA_PATH');
    $second = new YouTubeDurationService('');
    expect($second->getDurationFromCache('first') === 123, 'A new instance must read persisted durations');
    $second->saveDurationsToCache(['second' => 456, 'replace' => 20]);
    $third = new YouTubeDurationService('');
    expect($third->getDurationFromCache('first') === 123, 'Saving must preserve existing durations');
    expect($third->getDurationFromCache('second') === 456, 'Saving must add new durations');
    expect($third->getDurationFromCache('replace') === 20, 'Saving must update existing durations');
    $contents = json_decode((string) file_get_contents($cacheFile), true);
    expect(
        is_array($contents) && count($contents) === 3
            && ($contents['first'] ?? null) === 123
            && ($contents['second'] ?? null) === 456
            && ($contents['replace'] ?? null) === 20,
        'The cache file must contain the merged durations',
    );
    expect(!file_exists($logFile), 'Successful cache operations must not log failures');

    unlink($cacheFile);
    mkdir($cacheFile . '.tmp');
    $service->saveDurationsToCache(['write-failure' => 1]);
    clearstatcache();
    expect(!file_exists($cacheFile), 'A failed temporary write must not create a cache');
    expect(is_file($logFile) && trim((string) file_get_contents($logFile)) !== '', 'A failed write must be logged');
    unlink($logFile);
    rmdir($cacheFile . '.tmp');

    mkdir($cacheFile);
    $service->saveDurationsToCache(['rename-failure' => 2]);
    clearstatcache();
    expect(is_dir($cacheFile), 'A failed rename must not replace the destination directory');
    expect(is_file($logFile) && trim((string) file_get_contents($logFile)) !== '', 'A failed rename must be logged');

    fwrite(STDOUT, "Cache regression tests passed\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Cache regression test failed: ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    restore_error_handler();
    error_reporting($previousReporting);
    if ($previousLog !== false) {
        ini_set('error_log', $previousLog);
    }
    removeTree($root);
}

exit($exitCode);
