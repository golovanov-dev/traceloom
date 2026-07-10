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
    ) {
        $this->sanitizer = PayloadSanitizer::fromConfiguration($configuration);
        $this->metrics = new Metrics();
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
        return new self(
            configuration: $configuration,
            writer: new JsonlFileWriter($configuration),
            clock: new SystemClock(),
        );
    }

    /**
     * Starts a trace.
     *
     * $traceId is trusted by default, because the caller decided to pass it.
     * When it originates from an untrusted source (an inbound HTTP header on a
     * public endpoint), set Configuration::$trustIncomingTraceId to false: the
     * incoming value is then recorded as `parent_trace_id` and a fresh ID is
     * generated, so a client cannot write into someone else's trace.
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
     * Number of events this tracer failed to persist. Non-zero means the timeline
     * has holes; with failOnError disabled this is the only signal you get.
     */
    public function droppedEventCount(): int
    {
        return $this->metrics->droppedEvents();
    }

    public function flush(): void
    {
        $this->writer->flush();
    }

    public function close(): void
    {
        $this->writer->close();
    }
}
