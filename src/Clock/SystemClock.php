<?php

declare(strict_types=1);

namespace Golovanov\Clock;

final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function microtime(): float
    {
        return microtime(true);
    }
}
