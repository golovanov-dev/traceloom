# Changelog

## 0.2.0 - Unreleased

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

### Added (from the initial MVP)

- Trace-oriented public API with `Tracer` and `Trace`.
- Local JSONL storage with per-date files and size-based shards.
- Recursive sensitive-data masking and long-string truncation.
- Minimal CLI command `eventtrace show`.
