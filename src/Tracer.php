<?php

declare(strict_types=1);

namespace Golovanov\Traceloom;

use Golovanov\Traceloom\Clock\ClockInterface;
use Golovanov\Traceloom\Clock\SystemClock;
use Golovanov\Traceloom\Sanitizer\PayloadSanitizer;
use Golovanov\Traceloom\Writer\JsonlFileWriter;
use Golovanov\Traceloom\Writer\WriterInterface;

final class Tracer
{
    private readonly PayloadSanitizer $sanitizer;
    private readonly Metrics $metrics;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly WriterInterface $writer,
        private readonly ClockInterface $clock,
        ?Metrics $metrics = null,
    ) {
        $this->sanitizer = PayloadSanitizer::fromConfiguration($configuration);
        $this->metrics = $metrics ?? new Metrics();
    }

    /**
     * Everything default, writing JSONL into $logDirectory.
     */
    public static function fromDirectory(string $logDirectory): self
    {
        return self::fromConfiguration(Configuration::create(logDirectory: $logDirectory));
    }

    public static function fromConfiguration(Configuration $configuration): self
    {
        // One Metrics instance is shared with the writer, which is the only place that
        // can see a payload degrade.
        $metrics = new Metrics();

        return new self(
            configuration: $configuration,
            writer: new JsonlFileWriter($configuration, $metrics),
            clock: new SystemClock(),
            metrics: $metrics,
        );
    }

    /**
     * Starts a trace.
     *
     * An incoming $traceId is NOT trusted by default: it is recorded as
     * `parent_trace_id` and a fresh ID is generated, so a client that guesses or
     * replays another request's ID cannot write into that trace. Behind a gateway
     * that sets the header itself, set Configuration::$trustIncomingTraceId to true
     * to carry a single ID across the service boundary.
     */
    public function start(?string $traceId = null): Trace
    {
        $incoming = TraceId::sanitize($traceId);

        if ($incoming !== null && $this->configuration->trustIncomingTraceId) {
            $ownId = $incoming;
            $parentId = null;
        } else {
            $ownId = TraceId::generate();
            $parentId = $incoming;
        }

        return new Trace(
            traceId: $ownId,
            parentTraceId: $parentId,
            configuration: $this->configuration,
            writer: $this->writer,
            sanitizer: $this->sanitizer,
            clock: $this->clock,
            metrics: $this->metrics,
        );
    }

    /**
     * Events that never reached the log. Non-zero means the timeline has holes; with
     * failOnError disabled this and the gaps in `sequence` are the only signals.
     */
    public function droppedEventCount(): int
    {
        return $this->metrics->droppedEvents();
    }

    /**
     * Events that were recorded, but whose payload was replaced by an
     * `_encoding_error` marker (too large, or not encodable). The event survives, the
     * data does not.
     */
    public function degradedEventCount(): int
    {
        return $this->metrics->degradedEvents();
    }

    public function flush(): void
    {
        $this->writer->flush();
    }

    /**
     * Terminal: events recorded afterwards are rejected and counted as dropped.
     */
    public function close(): void
    {
        $this->writer->close();
    }
}
