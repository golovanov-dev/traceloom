<?php

/**
 * Worker for WriterConcurrencyTest: appends $events events to $directory and exits.
 *
 * Usage: php concurrent-writer.php <directory> <worker-id> <events> <max-file-bytes>
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Tracer;

$directory = $argv[1] ?? '';
$workerId = $argv[2] ?? '0';
$events = (int)($argv[3] ?? 0);
$maxFileBytes = (int)($argv[4] ?? Configuration::DEFAULT_MAX_FILE_BYTES);

$tracer = Tracer::fromConfiguration(Configuration::create(
    logDirectory: $directory,
    maxFileBytes: $maxFileBytes,
    failOnError: true,
));

$trace = $tracer->start('worker-' . $workerId . '-trace');

for ($i = 0; $i < $events; $i++) {
    $trace->event('tick', ['worker' => $workerId, 'i' => $i, 'pad' => str_repeat('x', 64)]);
}

$tracer->close();

exit($tracer->droppedEventCount() === 0 ? 0 : 1);
