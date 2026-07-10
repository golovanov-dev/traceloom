<?php

declare(strict_types=1);

namespace Golovanov\Tests\Unit;

use Golovanov\Configuration;
use Golovanov\Sanitizer\PayloadSanitizer;
use PHPUnit\Framework\TestCase;

final class PayloadSanitizerTest extends TestCase
{
    public function testMasksSensitiveKeysRecursively(): void
    {
        $sanitizer = new PayloadSanitizer(Configuration::create(logDirectory: __DIR__));

        $payload = $sanitizer->sanitize([
            'email' => 'user@example.com',
            'password' => 'secret',
            'nested' => [
                'Authorization' => 'Bearer token',
            ],
        ]);

        self::assertSame('user@example.com', $payload['email']);
        self::assertSame('[REDACTED]', $payload['password']);
        self::assertSame('[REDACTED]', $payload['nested']['Authorization']);
    }

    public function testTruncatesLongStringsWithMetadata(): void
    {
        $sanitizer = new PayloadSanitizer(Configuration::create(
            logDirectory: __DIR__,
            maxStringBytes: 4,
        ));

        $payload = $sanitizer->sanitize(['body' => 'abcdef']);

        self::assertSame([
            '_truncated' => true,
            'size_bytes' => 6,
            'preview' => 'abcd',
        ], $payload['body']);
    }

    public function testNormalizesUnsupportedValuesPredictably(): void
    {
        $sanitizer = new PayloadSanitizer(Configuration::create(logDirectory: __DIR__));
        $handle = fopen(__FILE__, 'rb');

        self::assertIsResource($handle);

        $payload = $sanitizer->sanitize(['handle' => $handle]);

        fclose($handle);

        self::assertSame('[UNSUPPORTED_TYPE: resource (stream)]', $payload['handle']);
    }
}
