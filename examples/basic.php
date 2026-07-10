<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Golovanov\Traceloom\Tracer;

$tracer = Tracer::fromDirectory(__DIR__ . '/../logs');

$trace = $tracer->start();
$trace->event('request_start', ['method' => 'POST', 'path' => '/orders']);
$trace->event('auth_success', ['user_id' => 42]);
$trace->event('billing_request', ['provider' => 'example']);
$trace->event('billing_response', ['status' => 200]);
$trace->event('request_end', ['status' => 201]);

echo 'Trace ID: ' . $trace->id() . PHP_EOL;
