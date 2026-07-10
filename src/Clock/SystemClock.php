<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Clock;

final class SystemClock implements ClockInterface
{
    private readonly \DateTimeZone $utc;

    public function __construct()
    {
        $this->utc = new \DateTimeZone('UTC');
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->utc);
    }

    public function monotonicNs(): int
    {
        return hrtime(true);
    }
}
