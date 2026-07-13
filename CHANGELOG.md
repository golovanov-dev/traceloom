# Changelog

## 0.3.0 - 2026-07-13

Aligns the JSONL contract with the Node.js and Go implementations, which had moved
ahead, and closes the security gaps they found. Several defaults change; in a `0.x`
line that is expected, and the notable ones are listed under **Changed**.

### Security

- **Sensitive-key masking now catches the spellings that actually occur.** Matching
  used a fragment list of `password`/`secret`/`token`/`apikey`/..., so `cookies`,
  `Cookie-Header`, `authorization_header`, `Authorization-Bearer`, `bearer`, `jwt`,
  `session`, `auth` and `access_key` all reached the log in clear text. The fragment
  and default-key lists are now in step with the JS and Go implementations.
- **A payload can no longer forge or overwrite a sanitizer marker.** A key spelling
  `_truncated`, `_binary`, `_encoding_error` or `_omitted_items` was written verbatim,
  so an untrusted source could pass its data off as sanitizer output, and a genuine
  `_omitted_items` could silently overwrite a user field. Colliding keys now gain a
  leading underscore (`_truncated` → `__truncated`), matching JS and Go.
- **An incoming trace ID is no longer trusted by default.** See **Changed**.
- **Retention no longer deletes files it did not write.** The cutoff matched any
  `*.jsonl` beginning with a date, so `2024-01-01-backup.jsonl` was removed. Only the
  library's own shard names (`<date>.jsonl`, `<date>-<n>.jsonl`) are now eligible.
- **The CLI escapes bidirectional and line-separator characters.** Escaping C0
  controls was not enough: U+202E (RIGHT-TO-LEFT OVERRIDE) can make one event name
  read as another in a terminal, and U+2028/U+2029 break the one-event-per-line output.

### Fixed

- **A failed write once again leaves a gap in `sequence` instead of renumbering
  around the loss.** 0.2.0 advanced the counter only after a successful write, which
  produced a dense `1, 2, 3` and made the loss invisible to anyone reading the file.
  `droppedEventCount()` lives in process memory and dies with it; the gap is the only
  durable signal, and it is what JS and Go emit.
- **`maxArrayItems` no longer lets one long list swallow its sibling fields.** It was
  a single budget for the entire payload, so a 1000-element array in the first field
  erased `user_id` and `status` from the rest of the event. It now limits each array
  on its own, and the new `maxPayloadNodes` bounds the payload as a whole.
- **Retention runs on every UTC date change, not once per process.** A long-lived
  process (daemon, worker, Swoole/RoadRunner) stopped expiring shards after its first
  write and grew until the disk filled.
- **`close()` is terminal.** A late event used to reopen the file, leaking the handle
  and reporting success to a caller that had already stopped tracing. Events after
  `close()` are now rejected and counted as dropped.
- **A short write no longer corrupts the following record.** A write that failed
  mid-line (a disk filling up) left a fragment with no newline, and the next event was
  appended straight onto it. The line boundary is now restored before the error is
  reported.
- The CLI bounds how much of a single line it will read, so a damaged file with no
  newlines cannot be pulled into memory whole.

### Added

- `maxPayloadNodes` (default 10 000): a node budget for the whole payload, separate
  from the per-array `maxArrayItems`.
- `Tracer::degradedEventCount()`: events that were recorded but whose payload was
  replaced by an `_encoding_error` marker. Degradation also reaches `onError` now;
  previously it was entirely silent, and `droppedEventCount()` stayed at zero.

### Changed

- **`trustIncomingTraceId` now defaults to `false`.** A client-supplied ID is kept as
  `parent_trace_id` and a fresh ID is generated, so a caller cannot write into another
  request's trace. Behind a gateway that sets the header itself, pass `true` to carry
  one ID across the service boundary.

## 0.2.0 - 2026-07-10

This release fixes three ways the tracer could break the host application or lose
events silently, and rebuilds the write path. It is a `0.x` line, so breaking changes
are expected; the notable ones are listed under **Changed**.

### Fixed

- **No longer crashes the host application.** A payload containing a circular
  reference (a self-referencing `JsonSerializable`, an ORM entity with a back
  reference, a node linked to its parent) used to recurse until the process died with
  an unrecoverable `Allowed memory size exhausted` fatal error, which no `catch` could
  intercept. The sanitizer now detects cycles (`[CIRCULAR_REFERENCE]`) and enforces a
  depth limit (`[MAX_DEPTH_EXCEEDED]`).
- **No longer drops events with non-ASCII payloads.** Long strings were truncated on
  a raw byte boundary, producing invalid UTF-8 that made `json_encode()` throw and
  silently discarded the entire event. Because the default limit (65536) is not a
  multiple of three, this hit any CJK payload over 64 KB out of the box. Truncation
  now cuts on a code-point boundary, invalid UTF-8 is reported as a `_binary` marker,
  and an unencodable payload degrades to a placeholder instead of taking the event
  down with it.
- **Secret masking now matches real-world key spellings.** Matching was exact, so
  `user_password`, `apiKey`, and `X-Api-Key` all leaked. Keys are now canonicalized
  (case, `-`, `_` folded) and matched against both an expanded default list and a set
  of fragments; add `strictSensitiveKeys: true` for whole-key-only matching.
- **`elapsed_ms` is now measured on a monotonic clock** (`hrtime`), so durations no
  longer collapse to zero or go wrong across NTP steps and DST transitions.
- **`sequence` no longer skips numbers on a failed write.** It advances only after a
  successful write, so a gap always means a lost event.
- **The CLI no longer forwards terminal escape sequences.** Event names and other
  values read from log files are escaped before printing, closing an ANSI-injection
  vector against operators. Malformed-line warnings are aggregated into one summary.
- Filesystem roots (`/`, `C:\`) are no longer mangled by trailing-separator trimming.

### Security

- Log directories are created `0750` and files `0640` (was world-readable), both
  configurable. See the README on keeping the log directory outside the web root.
- Untrusted inbound trace IDs can be quarantined with `trustIncomingTraceId: false`:
  the incoming value is recorded as `parent_trace_id` and a fresh ID is generated, so
  a client cannot write into another request's trace.
- Payload size is bounded (`maxRecordBytes`, `maxArrayItems`) to blunt memory/disk
  denial-of-service from attacker-shaped payloads.

### Performance

- The file handle is opened once and reused, the file size is cached, and the
  directory lock is taken only on rotation. Throughput rose from ~1.8k to ~50k
  events/sec (~540 us -> ~20 us per event).
- Rotation no longer stat()s every shard on every event. With 300 shards present,
  per-event cost dropped from ~18.8 ms to ~20 us.

### Changed

- **Namespace `Golovanov\` -> `Golovanov\Traceloom\`.** Update your imports
  (`use Golovanov\Traceloom\Tracer;`).
- **`Tracer::create()` is replaced by `Tracer::fromDirectory()` and
  `Tracer::fromConfiguration()`.**
- `WriterInterface::write()` now takes a `TraceEvent` value object instead of an
  array, and the interface gained `flush()` and `close()`.
- `Tracer` gained `droppedEventCount()`, `flush()`, and `close()`.
- Empty event names now always throw (programming error); names with control
  characters are stripped rather than rejected.
- Added `retentionDays` for deleting shards older than the cutoff on rotation.

## 0.1.0 - 2026-07-10

Initial release.

### Added

- Trace-oriented public API with `Tracer` and `Trace`.
- Local JSONL storage with per-date files and size-based shards.
- Recursive sensitive-data masking and long-string truncation.
- Minimal CLI command `eventtrace show`.
