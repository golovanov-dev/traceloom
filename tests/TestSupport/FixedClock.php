<?php

declare(strict_types=1);

namespace Golovanov\Tests\TestSupport;

use Golovanov\Clock\ClockInterface;

final class FixedClock implements ClockInterface
{
    private int $tick = 0;

    public function __construct(
        private readonly \DateTimeImmutable $start,
        private readonly float $microStart = 1000.0,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->start->modify('+' . $this->tick . ' milliseconds');
    }

    public function microtime(): float
    {
        return $this->microStart + (($this->tick++) * 0.001);
    }
}
