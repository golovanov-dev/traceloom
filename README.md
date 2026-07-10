# Traceloom

A lightweight PHP library for recording structured event timelines grouped by trace ID.

Traceloom is for small PHP APIs, backend services, jobs, webhooks, and scripts where you want to reconstruct what happened during one logical process without running a full observability stack.

It is not a replacement for Monolog, OpenTelemetry, or a distributed tracing platform.

## Installation

```bash
composer require golovanov/traceloom
```

## Quick Start

```php
use Golovanov\Tracer;

$tracer = Tracer::create(logDirectory: __DIR__ . '/logs');

$trace = $tracer->start();

$trace->event('request_start', [
    'method' => 'POST',
    'path' => '/orders',
]);

$trace->event('auth_success', [
    'user_id' => 42,
]);

$trace->event('request_end', [
    'status' => 201,
]);
```

All events written through the same `Trace` object share the same `trace_id`.

## JSONL Output

```json
{"timestamp":"2026-07-10T10:41:20.112345Z","trace_id":"9f1a8e7c2d4b4a9f93e2b2b1454f0c0a","event":"request_start","sequence":1,"elapsed_ms":0.121,"data":{"method":"POST","path":"/orders"}}
{"timestamp":"2026-07-10T10:41:20.117381Z","trace_id":"9f1a8e7c2d4b4a9f93e2b2b1454f0c0a","event":"auth_success","sequence":2,"elapsed_ms":5.157,"data":{"user_id":42}}
{"timestamp":"2026-07-10T10:41:20.289842Z","trace_id":"9f1a8e7c2d4b4a9f93e2b2b1454f0c0a","event":"request_end","sequence":3,"elapsed_ms":177.618,"data":{"status":201}}
```

## Continue An Existing Trace

```php
$trace = $tracer->start($incomingTraceId);
$trace->event('webhook_received');
```

Invalid incoming IDs are replaced with a generated ID. Generated IDs are 32-character random hex strings.

## Finding A Trace

After installing Traceloom as a dependency, Composer exposes the bundled CLI through `vendor/bin`:

```bash
vendor/bin/eventtrace show 9f1a8e7c2d4b4a9f93e2b2b1454f0c0a --dir=logs
```

When working inside the Traceloom source repository itself, run the package binary directly:

```bash
php bin/eventtrace show 9f1a8e7c2d4b4a9f93e2b2b1454f0c0a --dir=logs
```

Example output:

```text
Trace: 9f1a8e7c2d4b4a9f93e2b2b1454f0c0a
10:41:20.112 request_start
10:41:20.117 auth_success +5 ms
10:41:20.289 request_end +172 ms
Total duration: 177 ms
```

The MVP CLI intentionally includes only `show`. More commands such as `tail` or event search belong in a later phase.

## Configuration

```php
use Golovanov\Configuration;
use Golovanov\Tracer;

$config = Configuration::create(
    logDirectory: __DIR__ . '/logs',
    maxFileBytes: 50 * 1024 * 1024,
    maxStringBytes: 64 * 1024,
    sensitiveKeys: ['payment_token'],
    failOnError: false,
    onError: static function (\Throwable $e): void {
        error_log($e->getMessage());
    },
);

$tracer = Tracer::create($config);
```

Traceloom writes files by date and rotates into shards when the active file grows beyond `maxFileBytes`:

```text
logs/
  2026-07-10.jsonl
  2026-07-10-1.jsonl
  2026-07-10-2.jsonl
```

File selection and append are protected by a lock file in the log directory.

## Sensitive Data

Sensitive key masking is enabled by default and runs recursively. Built-in keys include:

`password`, `token`, `access_token`, `refresh_token`, `authorization`, `cookie`, `api_key`, `secret`, `client_secret`.

Masked values are written as:

```json
"[REDACTED]"
```

Custom keys are merged with the defaults through `sensitiveKeys`.

## Payload Limits

Long strings are replaced with explicit truncation metadata:

```json
{
  "_truncated": true,
  "size_bytes": 250000,
  "preview": "..."
}
```

Supported payload values are arrays, strings, integers, floats, booleans, null, `JsonSerializable`, and `Stringable`. Unsupported values are replaced with an explicit marker.

## HTTP Integration

For new integrations, prefer `X-Trace-Id`:

```php
$trace = $tracer->start($request->header('X-Trace-Id'));
```

If your system already uses `X-Request-Id` for request correlation, it is fine to continue that trace:

```php
$trace = $tracer->start($request->header('X-Request-Id'));
```

Minimal request flow:

```php
$trace->event('request_start', [
    'method' => $request->method(),
    'path' => $request->path(),
]);

// application processing

$trace->event('request_end', [
    'status' => $response->statusCode(),
]);
```

Framework adapters for PSR-7, Laravel, Symfony, or custom frameworks are intentionally outside the MVP core.

## Error Handling

Tracing is fail-safe by default: write errors should not break the main application.

Use `failOnError: true` when you want tracing failures to throw exceptions, usually in tests or strict local development.

Configuration errors always throw.

## When Not To Use Traceloom

Use Monolog if you need a general-purpose logging framework.

Use OpenTelemetry if you need industry-standard distributed tracing.

Use a log platform or observability stack if you need aggregation, dashboards, alerting, retention policy, or cross-service querying.

Traceloom is intentionally small: local structured timeline tracing without external infrastructure.
