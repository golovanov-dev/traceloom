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
use Golovanov\Traceloom\Tracer;

$tracer = Tracer::fromDirectory(__DIR__ . '/logs');

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

An incoming ID is **not** trusted by default: it is recorded as `parent_trace_id` and a
fresh ID is generated. Behind a gateway that sets the header itself, opt in to reusing
it (see [Trusting Incoming Trace IDs](#trusting-incoming-trace-ids)).

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
use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Tracer;

$config = Configuration::create(
    logDirectory: __DIR__ . '/logs',
    maxFileBytes: 50 * 1024 * 1024,   // rotate a shard past this size
    maxStringBytes: 64 * 1024,        // truncate longer strings (clamped to maxRecordBytes)
    maxRecordBytes: 256 * 1024,       // degrade an event whose line exceeds this (clamped to maxFileBytes)
    maxArrayItems: 1000,              // limit on EACH array, on its own
    maxPayloadNodes: 10000,           // node budget for the payload as a whole
    maxKeyBytes: 256,                 // longer keys are truncated with a digest (min 32)
    maxDepth: 16,                     // nesting beyond this becomes [MAX_DEPTH_EXCEEDED]
    sensitiveKeys: ['payment_token'],
    strictSensitiveKeys: false,       // true => match whole keys only, no fragments
    directoryMode: 0750,
    fileMode: 0640,
    retentionDays: 0,                 // >0 deletes shards older than the cutoff on rotation
    trustIncomingTraceId: false,      // true reuses an inbound ID instead of quarantining it
    failOnError: false,
    onError: static function (\Throwable $e): void {
        error_log($e->getMessage());
    },
);

$tracer = Tracer::fromConfiguration($config);
```

`maxRecordBytes` is clamped to `maxFileBytes`, and `maxStringBytes` to `maxRecordBytes`,
so a single event can never overflow a shard.

Traceloom writes files by date and rotates into shards when the active file grows beyond `maxFileBytes`:

```text
logs/
  2026-07-10.jsonl
  2026-07-10-1.jsonl
  2026-07-10-2.jsonl
```

The active file handle is reused across events; the directory lock is taken only when
a shard has to be selected or rotated.

## File Permissions

Log directories are created `0750` and files `0640`, so they are not world-readable.
Both are configurable via `directoryMode` and `fileMode`.

Trace files hold request bodies, user identifiers, and (despite masking) potentially
sensitive values. **Keep the log directory outside your web root** so it cannot be
fetched over HTTP. Traceloom cannot enforce this for you.

## Sensitive Data

Sensitive key masking is enabled by default and runs recursively. Keys are compared
after folding case and removing `-` and `_`, so `api_key`, `apiKey`, `API-KEY`, and
`X-Api-Key` are all recognized. Built-in keys include `password`, `token`,
`access_token`, `refresh_token`, `authorization`, `cookie`, `set_cookie`, `api_key`,
`x_api_key`, `secret`, `client_secret`, `private_key`, `credentials`, `signature`,
`session_id`, and `csrf`.

By default a key is also masked if it *contains* a known secret fragment (`password`,
`secret`, `token`, `apikey`, `privatekey`, `credential`), so `user_password` and
`stripe_secret` are caught. Set `strictSensitiveKeys: true` to require a whole-key
match instead.

Masked values are written as:

```json
"[REDACTED]"
```

Custom keys are merged with the defaults through `sensitiveKeys`.

Masking is keyed on names, not values: a secret placed under an innocuous key (`note`,
`body`) is not detected. Do not pass raw credentials under arbitrary keys.

## Trusting Incoming Trace IDs

An inbound trace ID is **not trusted by default**. On a public endpoint the header is
attacker-controlled, and a client that guesses or replays another request's ID could
otherwise write its own events into that trace and corrupt an investigation. The
incoming value is stored as `parent_trace_id`, and a fresh ID is generated:

```php
$trace = $tracer->start($request->header('X-Trace-Id'));

$trace->id();        // freshly generated
$trace->parentId();  // the value the client sent
```

Behind a gateway or service mesh that sets the header itself, opt in to reusing it so a
single ID spans the whole call chain:

```php
$tracer = Tracer::fromConfiguration(Configuration::create(
    logDirectory: __DIR__ . '/logs',
    trustIncomingTraceId: true,
));
```

## Payload Limits

Long strings are replaced with explicit truncation metadata (the preview is cut on a
UTF-8 code-point boundary, never mid-character):

```json
{
  "_truncated": true,
  "size_bytes": 250000,
  "preview": "..."
}
```

Supported payload values are arrays, strings, integers, floats, booleans, null, `JsonSerializable`, and `Stringable`.

Other cases are replaced with an explicit marker rather than being allowed to break
the write:

| Marker | Cause |
| --- | --- |
| `{"_binary": true, ...}` | String is not valid UTF-8 (preview is hex) |
| `[CIRCULAR_REFERENCE]` | Value refers back to itself |
| `[MAX_DEPTH_EXCEEDED]` | Nesting deeper than `maxDepth` |
| `[SERIALIZATION_FAILED: Class]` | A `jsonSerialize()`/`__toString()` threw |
| `[UNSUPPORTED_TYPE: type]` | Value is a resource or other unsupported type |
| `{"_omitted_items": N}` | Entries dropped past `maxArrayItems` or `maxPayloadNodes` |

Two independent limits bound a payload. `maxArrayItems` caps **each array on its own**,
so one long list cannot push its sibling fields out of the event; `maxPayloadNodes` caps
the payload **as a whole**, which is what stops a wide or deeply nested input bomb.

Keys are bounded too. A key longer than `maxKeyBytes` is cut on a code-point boundary
and given a digest of the **original** key, so that keys sharing a long prefix stay
distinct instead of collapsing into one and overwriting each other:

```text
"very_long_key_from_untrusted_json…"  →  "very_long_key_fro…~3f2a9c1e5b7d4088"
```

The result is an ordinary string key — no JSONL consumer has to change — and the mapping
is deterministic across processes, runs, and the PHP, JS and Go implementations alike.
Masking is decided on the original key, so truncation cannot smuggle a secret past it.

If a whole event still cannot be encoded, its `data` is replaced with
`{"_encoding_error": "..."}` so the event stays in the timeline instead of vanishing.
That is a real loss of data, so it is counted — see [Error Handling](#error-handling).

### Reserved Keys

`_truncated`, `_binary`, `_encoding_error` and `_omitted_items` belong to the
sanitizer. A payload key that spells one of them gains a leading underscore
(`_truncated` → `__truncated`), so a record cannot claim to be sanitizer output when it
is not, and a genuine marker can never overwrite a field of yours. The escape is itself
escaped, so the mapping back is unambiguous.

## HTTP Integration

For new integrations, prefer `X-Trace-Id`:

```php
$trace = $tracer->start($request->header('X-Trace-Id'));
```

The header is quarantined as `parent_trace_id` unless you opt in (see
[Trusting Incoming Trace IDs](#trusting-incoming-trace-ids)).

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

Configuration errors always throw. An empty event name always throws too — it is a
programming error, not a runtime failure — regardless of `failOnError`.

Even in fail-safe mode data can be lost, in two different ways, and neither is silent:

```php
$tracer->droppedEventCount();    // the event never reached the log
$tracer->degradedEventCount();   // the event was written, its payload was not
```

An event is **dropped** when the write fails (a full disk, a permission error). It is
**degraded** when its payload could not be encoded or exceeded `maxRecordBytes`: the
record survives with `data` replaced by `{"_encoding_error": "..."}`, because an event
with a placeholder is worth more than a hole in the timeline. Both cases also reach
`onError`.

A dropped event leaves a **gap in `sequence`**. That gap is deliberate: the counters
above live in the process's memory and die with it, while the log file outlives both,
so the gap is the only signal to whoever reads the JSONL later that the timeline is
incomplete.

`$tracer->flush()` forces buffered data to the OS. `$tracer->close()` is **terminal**:
it releases the file handle, and events recorded afterwards are rejected and counted as
dropped rather than silently reopening the file. The handle is also flushed and closed
when the tracer is destroyed, so calling either is optional.

## When Not To Use Traceloom

Use Monolog if you need a general-purpose logging framework.

Use OpenTelemetry if you need industry-standard distributed tracing.

Use a log platform or observability stack if you need aggregation, dashboards, alerting, retention policy, or cross-service querying.

Traceloom is intentionally small: local structured timeline tracing without external infrastructure.
