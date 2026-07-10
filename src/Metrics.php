<?php

declare(strict_types=1);

namespace Golovanov\Traceloom;

/**
 * Shared, mutable counters for one Tracer and every Trace it starts.
 *
 * Silent event loss is the failure mode of a fail-safe tracer. Without a counter
 * there is no way for an application to notice it is happening.
 */
final class Metrics
{
    private int $droppedEvents = 0;

    public function recordDroppedEvent(): void
    {
        $this->droppedEvents++;
    }

    public function droppedEvents(): int
    {
        return $this->droppedEvents;
    }
}
