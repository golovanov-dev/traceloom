<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Tests\Unit;

use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Sanitizer\PayloadSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayloadSanitizerTest extends TestCase
{
    private static function sanitizer(mixed ...$options): PayloadSanitizer
    {
        /** @var array<string, mixed> $options */
        $options['logDirectory'] = __DIR__;

        return PayloadSanitizer::fromConfiguration(Configuration::create(...$options));
    }

    public function testMasksSensitiveKeysRecursively(): void
    {
        $payload = self::sanitizer()->sanitize([
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

    /**
     * Regression: exact-match lookup missed every real-world spelling of a secret key.
     */
    public function testMasksCompositeCamelCaseAndDashedKeys(): void
    {
        $payload = self::sanitizer()->sanitize([
            'user_password' => 'p1',
            'apiKey' => 'p2',
            'X-Api-Key' => 'p3',
            'stripe_secret' => 'p4',
            'refreshToken' => 'p5',
            'username' => 'kept',
        ]);

        self::assertSame('[REDACTED]', $payload['user_password']);
        self::assertSame('[REDACTED]', $payload['apiKey']);
        self::assertSame('[REDACTED]', $payload['X-Api-Key']);
        self::assertSame('[REDACTED]', $payload['stripe_secret']);
        self::assertSame('[REDACTED]', $payload['refreshToken']);
        self::assertSame('kept', $payload['username']);
    }

    /**
     * Regression: the fragment list held only password/secret/token/apikey/..., so the
     * spellings that actually show up when dumping HTTP headers went to disk in clear
     * text. Ten of these twelve leaked. The list must stay identical across PHP, JS
     * and Go, or the same payload gets masked differently depending on who wrote it.
     */
    public function testMasksRealWorldHeaderSpellings(): void
    {
        $keys = [
            'cookies',
            'Cookie-Header',
            'authorization_header',
            'Authorization-Bearer',
            'bearer',
            'jwt',
            'jwt_value',
            'session',
            'auth',
            'api_key',
            'access_key',
            'password',
        ];

        $payload = self::sanitizer()->sanitize(array_fill_keys($keys, 'SECRET'));
        $leaked = array_keys(array_filter($payload, static fn (mixed $v): bool => $v === 'SECRET'));

        self::assertSame([], $leaked, 'these must never reach the log in clear text');
    }

    /**
     * The fragment list must not be so eager that it redacts ordinary fields.
     */
    public function testDoesNotRedactInnocentKeys(): void
    {
        $payload = self::sanitizer()->sanitize([
            'author' => 'a',
            'keyboard' => 'b',
            'monkey' => 'c',
            'username' => 'd',
            'email' => 'e',
        ]);

        self::assertSame(['author' => 'a', 'keyboard' => 'b', 'monkey' => 'c', 'username' => 'd', 'email' => 'e'], $payload);
    }

    public function testStrictModeMatchesWholeKeysOnly(): void
    {
        $lenient = self::sanitizer()->sanitize(['token_count' => 7]);
        $strict = self::sanitizer(strictSensitiveKeys: true)->sanitize(['token_count' => 7]);

        self::assertSame('[REDACTED]', $lenient['token_count'], 'fragment match is the default');
        self::assertSame(7, $strict['token_count']);
    }

    public function testTruncatesLongStringsWithMetadata(): void
    {
        $payload = self::sanitizer(maxStringBytes: 4)->sanitize(['body' => 'abcdef']);

        self::assertSame([
            '_truncated' => true,
            'size_bytes' => 6,
            'preview' => 'abcd',
        ], $payload['body']);
    }

    /**
     * Regression: byte-wise substr() split multi-byte code points, producing invalid
     * UTF-8 that made json_encode() throw and silently destroyed the whole event.
     */
    #[DataProvider('multiByteTruncationProvider')]
    public function testTruncationNeverSplitsACodePoint(string $value, int $maxBytes): void
    {
        $payload = self::sanitizer(maxStringBytes: $maxBytes)->sanitize(['body' => $value]);

        self::assertIsArray($payload['body']);
        $preview = $payload['body']['preview'];

        self::assertIsString($preview);
        self::assertLessThanOrEqual($maxBytes, strlen($preview));
        self::assertSame(1, preg_match('//u', $preview), 'preview must be valid UTF-8');
        self::assertNotFalse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function multiByteTruncationProvider(): iterable
    {
        $cyrillic = str_repeat('привет', 5);   // 2-byte code points
        $cjk = str_repeat('日本語', 5);         // 3-byte code points
        $emoji = str_repeat('😀', 5);          // 4-byte code points

        foreach ([9, 10, 11] as $max) {
            yield "cyrillic/{$max}" => [$cyrillic, $max];
        }

        foreach ([7, 8, 9] as $max) {
            yield "cjk/{$max}" => [$cjk, $max];
        }

        foreach ([5, 6, 7] as $max) {
            yield "emoji/{$max}" => [$emoji, $max];
        }
    }

    /**
     * Regression: 65536 % 3 == 1, so the default limit split every long CJK string.
     */
    public function testDefaultLimitTruncatesCjkSafely(): void
    {
        $payload = self::sanitizer()->sanitize(['body' => str_repeat('日', 30000)]);

        self::assertIsArray($payload['body']);
        self::assertNotFalse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testReplacesInvalidUtf8WithBinaryMarker(): void
    {
        $payload = self::sanitizer()->sanitize(['blob' => "\xff\xfe\x00"]);

        self::assertSame(
            ['_binary' => true, 'size_bytes' => 3, 'preview' => 'fffe00'],
            $payload['blob'],
        );
        self::assertNotFalse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Regression: a self-referencing JsonSerializable recursed until the process
     * died with an unrecoverable "Allowed memory size exhausted" fatal error.
     */
    public function testDetectsCircularObjectReferences(): void
    {
        $object = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return $this;
            }
        };

        $payload = self::sanitizer()->sanitize(['o' => $object]);

        self::assertSame('[CIRCULAR_REFERENCE]', $payload['o']);
    }

    public function testDetectsMutuallyRecursiveObjects(): void
    {
        $left = new \stdClass();
        $right = new \stdClass();

        $wrap = static function (\stdClass $target): \JsonSerializable {
            return new class ($target) implements \JsonSerializable {
                public function __construct(private readonly \stdClass $target)
                {
                }

                public function jsonSerialize(): mixed
                {
                    return ['peer' => $this->target->peer ?? null];
                }
            };
        };

        $left->peer = $wrap($right);
        $right->peer = $wrap($left);

        $payload = self::sanitizer()->sanitize(['root' => $left->peer]);

        self::assertNotFalse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testStopsAtMaxDepth(): void
    {
        $deep = 'leaf';

        for ($i = 0; $i < 50; $i++) {
            $deep = ['n' => $deep];
        }

        $payload = self::sanitizer(maxDepth: 5)->sanitize(['d' => $deep]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertIsString($encoded);
        self::assertStringContainsString('[MAX_DEPTH_EXCEEDED]', $encoded);
    }

    public function testRecursiveArrayIsBoundedByDepthLimit(): void
    {
        $array = ['name' => 'root'];
        $array['self'] = &$array;

        $payload = self::sanitizer(maxDepth: 4)->sanitize(['a' => $array]);

        self::assertNotFalse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Regression: maxArrayItems used to be a single budget for the whole payload, so
     * one long list swallowed its sibling fields — `zz_id` disappeared because of an
     * array in `aa_items`. JS and Go split the two limits; this keeps PHP aligned.
     */
    public function testMaxArrayItemsLimitsEachArraySeparately(): void
    {
        $payload = self::sanitizer(maxArrayItems: 4)->sanitize([
            'first' => [1, 2, 3, 4, 5, 6],
            'second' => [1, 2, 3, 4, 5, 6],
            'user_id' => 42,
        ]);

        self::assertSame(42, $payload['user_id'], 'a long list must not swallow its siblings');
        self::assertSame([1, 2, 3, 4, ['_omitted_items' => 2]], $payload['first']);
        self::assertSame([1, 2, 3, 4, ['_omitted_items' => 2]], $payload['second']);
    }

    public function testMaxPayloadNodesBoundsTheWholePayload(): void
    {
        $payload = self::sanitizer(maxPayloadNodes: 3)->sanitize([
            'a' => 1,
            'b' => 2,
            'c' => 3,
            'd' => 4,
            'e' => 5,
        ]);

        self::assertSame(2, $payload['_omitted_items'], 'counts every entry left unvisited');
        self::assertArrayNotHasKey('d', $payload);
        self::assertArrayNotHasKey('e', $payload);
    }

    /**
     * A payload must not be able to spell a marker the sanitizer writes: it would be
     * indistinguishable from a real one, which both misreports and forges the log.
     */
    public function testEscapesPayloadKeysThatSpellReservedMarkers(): void
    {
        $payload = self::sanitizer()->sanitize([
            '_encoding_error' => 'FAKE',
            '_truncated' => true,
            '_binary' => 'x',
            '_omitted_items' => 99,
            '__truncated' => 'already escaped',
        ]);

        // Each reserved spelling gains one underscore...
        self::assertSame('FAKE', $payload['__encoding_error']);
        self::assertTrue($payload['__truncated']);
        self::assertSame('x', $payload['__binary']);
        self::assertSame(99, $payload['__omitted_items']);

        // ...including one that was already escaped, so the mapping back is unambiguous.
        self::assertSame('already escaped', $payload['___truncated']);

        // No forged marker survives under its own name.
        foreach (['_encoding_error', '_truncated', '_binary', '_omitted_items'] as $marker) {
            self::assertArrayNotHasKey($marker, $payload);
        }
    }

    public function testRealOmittedMarkerIsNotOverwrittenByUserData(): void
    {
        $payload = self::sanitizer(maxPayloadNodes: 2)->sanitize([
            'a' => 1,
            'b' => 2,
            'c' => 3,
            '_omitted_items' => 'user-value',
        ]);

        self::assertSame(2, $payload['_omitted_items'], 'the genuine marker wins its own name');
    }

    public function testFailingSerializerDoesNotCostTheEvent(): void
    {
        $object = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('application bug');
            }
        };

        $payload = self::sanitizer()->sanitize(['o' => $object, 'kept' => 1]);

        self::assertStringStartsWith('[SERIALIZATION_FAILED:', (string)$payload['o']);
        self::assertSame(1, $payload['kept']);
    }

    public function testReplacesNonFiniteFloats(): void
    {
        $payload = self::sanitizer()->sanitize(['nan' => NAN, 'inf' => INF, 'ok' => 1.5]);

        self::assertNotFalse(json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertSame(1.5, $payload['ok']);
    }

    public function testNormalizesUnsupportedValuesPredictably(): void
    {
        $handle = fopen(__FILE__, 'rb');
        self::assertIsResource($handle);

        $payload = self::sanitizer()->sanitize(['handle' => $handle]);

        fclose($handle);

        self::assertSame('[UNSUPPORTED_TYPE: resource (stream)]', $payload['handle']);
    }
}
