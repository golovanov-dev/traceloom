<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Tracer;

// This endpoint is public, so the inbound header is attacker-controlled. The default
// (trustIncomingTraceId: false) is what we want here: the incoming value is kept as
// `parent_trace_id` and a fresh ID is generated, so a client cannot write its events
// into someone else's trace. Behind a gateway that sets the header itself, pass
// trustIncomingTraceId: true to carry one ID end to end.
$tracer = Tracer::fromConfiguration(Configuration::create(
    logDirectory: __DIR__ . '/../logs',
));

$incomingTraceId = $_SERVER['HTTP_X_TRACE_ID']
    ?? $_SERVER['HTTP_X_REQUEST_ID']
    ?? null;

$trace = $tracer->start(is_string($incomingTraceId) ? $incomingTraceId : null);

$trace->event('request_start', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'path' => parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/',
]);

try {
    $trace->event('application_step', ['name' => 'example']);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Trace-Id: ' . $trace->id());

    echo json_encode(['ok' => true, 'trace_id' => $trace->id()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $trace->event('request_end', ['status' => 200]);
} catch (\Throwable $exception) {
    $trace->event('request_error', [
        'type' => $exception::class,
        'message' => $exception->getMessage(),
    ]);

    throw $exception;
}
