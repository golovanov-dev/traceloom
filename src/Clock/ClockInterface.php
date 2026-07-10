<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Clock;

interface ClockInterface
{
    /**
     * Wall-clock reading, used for the human-readable `timestamp` field.
     */
    public function now(): \DateTimeImmutable;

    /**
     * Monotonic reading in nanoseconds, used for `elapsed_ms`.
     *
     * Must never move backwards, so durations stay correct across NTP steps
     * and daylight-saving transitions. The origin is arbitrary; only
     * differences between two readings are meaningful.
     */
    public function monotonicNs(): int;
}
