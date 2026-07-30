<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Golovanov\Traceloom\Tracer;

// Defaults are shared with the Node.js and Go benchmarks (10 000 events,
// payload {index, value}) so that the three numbers are comparable. Changing
// them here means changing them in benchmarks/event.mjs and
// benchmarks/event/main.go too, otherwise the results stop meaning the same thing.
$events = (int)($argv[1] ?? 10000);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'traceloom-benchmark-' . bin2hex(random_bytes(4));

$tracer = Tracer::fromDirectory($directory);
$trace = $tracer->start();

$start = microtime(true);

for ($i = 0; $i < $events; $i++) {
    $trace->event('benchmark_event', ['index' => $i, 'value' => 'small payload']);
}

$elapsed = microtime(true) - $start;

printf("events: %d\n", $events);
printf("total_time: %.4f s\n", $elapsed);
printf("events_per_second: %.2f\n", $events / max($elapsed, 0.000001));
printf("avg_event_time: %.4f ms\n", ($elapsed / max($events, 1)) * 1000);
printf("log_directory: %s\n", $directory);
