<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Tests\Unit;

use Golovanov\Traceloom\TraceId;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testSanitizeReportsRejectionInsteadOfGenerating(): void
    {
        self::assertNull(TraceId::sanitize(null));
        self::assertNull(TraceId::sanitize(''));
        self::assertNull(TraceId::sanitize('short'));
        self::assertNull(TraceId::sanitize(str_repeat('a', 129)));
        self::assertSame('valid-trace-id', TraceId::sanitize('valid-trace-id'));
    }

    /**
     * `$` without the `D` modifier also matches before a trailing newline. trim()
     * removes it today, so this guards the pattern against a future caller that
     * forgets to trim.
     */
    #[DataProvider('controlCharacterProvider')]
    public function testRejectsEmbeddedControlCharacters(string $traceId): void
    {
        self::assertNull(TraceId::sanitize($traceId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function controlCharacterProvider(): iterable
    {
        yield 'trailing newline' => ["valid-trace\nx"];
        yield 'embedded newline' => ["valid\ntrace-id"];
        yield 'escape sequence' => ["valid\033[31mtrace"];
        yield 'null byte' => ["valid\0trace-id"];
        yield 'space' => ['valid trace id'];
    }
}
