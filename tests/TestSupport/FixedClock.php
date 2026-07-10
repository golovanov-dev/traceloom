<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Tests\TestSupport;

use Golovanov\Traceloom\Clock\ClockInterface;

/**
 * Advances by exactly one millisecond per monotonicNs() reading, so elapsed_ms is
 * deterministic. now() reports the same tick, keeping timestamp and elapsed_ms in step.
 */
final class FixedClock implements ClockInterface
{
    private int $tick = 0;

    public function __construct(
        private readonly \DateTimeImmutable $start,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->start->modify('+' . $this->tick . ' milliseconds');
    }

    public function monotonicNs(): int
    {
        return ($this->tick++) * 1_000_000;
    }
}
