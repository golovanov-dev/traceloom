<?php

declare(strict_types=1);

namespace Golovanov\Clock;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;

    public function microtime(): float;
}
