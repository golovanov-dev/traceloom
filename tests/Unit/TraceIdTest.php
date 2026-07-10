<?php

declare(strict_types=1);

namespace Golovanov\Tests\Unit;

use Golovanov\TraceId;
use PHPUnit\Framework\TestCase;

final class TraceIdTest extends TestCase
{
    public function testGeneratesRandomHexTraceId(): void
    {
        $traceId = TraceId::generate();

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $traceId);
        self::assertNotSame($traceId, TraceId::generate());
    }

    public function testKeepsValidIncomingTraceId(): void
    {
        self::assertSame('request-123_ABC', TraceId::normalize(' request-123_ABC '));
    }

    public function testReplacesInvalidIncomingTraceId(): void
    {
        $traceId = TraceId::normalize('../bad id');

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $traceId);
    }
}
