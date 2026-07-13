<?php

declare(strict_types=1);

namespace Golovanov\Traceloom;

/**
 * Shared, mutable counters for one Tracer, every Trace it starts, and its writer.
 *
 * Silent loss is the failure mode of a fail-safe tracer. Without these counters an
 * application has no way to notice it is happening.
 */
final class Metrics
{
    private int $droppedEvents = 0;
    private int $degradedEvents = 0;

    /** An event never reached the log: the write failed. */
    public function recordDroppedEvent(): void
    {
        $this->droppedEvents++;
    }

    /** An event was recorded, but its payload was replaced by an encoding marker. */
    public function recordDegradedEvent(): void
    {
        $this->degradedEvents++;
    }

    public function droppedEvents(): int
    {
        return $this->droppedEvents;
    }

    public function degradedEvents(): int
    {
        return $this->degradedEvents;
    }
}
