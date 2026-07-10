<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Golovanov\Tracer;

$events = (int)($argv[1] ?? 10000);
$payloadSize = (int)($argv[2] ?? 128);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'traceloom-benchmark-' . bin2hex(random_bytes(4));

$tracer = Tracer::create(logDirectory: $directory);
$trace = $tracer->start();
$payload = ['body' => str_repeat('x', $payloadSize)];

$start = microtime(true);

for ($i = 0; $i < $events; $i++) {
    $trace->event('benchmark_event', $payload);
}

$elapsed = microtime(true) - $start;

printf("events: %d\n", $events);
printf("payload_size: %d bytes\n", $payloadSize);
printf("total_time: %.4f s\n", $elapsed);
printf("events_per_second: %.2f\n", $events / max($elapsed, 0.000001));
printf("avg_event_time: %.4f ms\n", ($elapsed / max($events, 1)) * 1000);
printf("log_directory: %s\n", $directory);
