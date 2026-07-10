<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Tests\Unit;

use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testRejectsEmptyDirectory(): void
    {
        $this->expectException(ConfigurationException::class);

        Configuration::create(logDirectory: '   ');
    }

    public function testStripsTrailingSeparatorsButKeepsFilesystemRoots(): void
    {
        self::assertSame('logs', Configuration::create(logDirectory: 'logs/')->logDirectory);
        self::assertSame(DIRECTORY_SEPARATOR, Configuration::create(logDirectory: '/')->logDirectory);

        // Regression: rtrim() turned "C:\" into "C:", which Windows reads as
        // "the current directory on drive C", not the drive root.
        self::assertSame('C:' . DIRECTORY_SEPARATOR, Configuration::create(logDirectory: 'C:\\')->logDirectory);
    }

    /**
     * A record must never be able to overflow a shard, otherwise rotation degrades
     * into one file per event.
     */
    public function testClampsRecordAndStringLimitsToFileLimit(): void
    {
        $configuration = Configuration::create(
            logDirectory: 'logs',
            maxFileBytes: 500,
            maxRecordBytes: 100_000,
            maxStringBytes: 100_000,
        );

        self::assertSame(500, $configuration->maxRecordBytes);
        self::assertSame(500, $configuration->maxStringBytes);
    }

    public function testRejectsNonPositiveLimits(): void
    {
        $this->expectException(ConfigurationException::class);

        Configuration::create(logDirectory: 'logs', maxDepth: 0);
    }

    public function testRejectsNegativeRetention(): void
    {
        $this->expectException(ConfigurationException::class);

        Configuration::create(logDirectory: 'logs', retentionDays: -1);
    }

    public function testCanonicalizesKeysToACommonForm(): void
    {
        self::assertSame('apikey', Configuration::canonicalizeKey('api_key'));
        self::assertSame('apikey', Configuration::canonicalizeKey('apiKey'));
        self::assertSame('apikey', Configuration::canonicalizeKey('APIKEY'));

        // Prefixed header spellings keep their prefix; they are covered by the
        // `x_api_key` default and by the `apikey` fragment, not by exact equality.
        self::assertSame('xapikey', Configuration::canonicalizeKey('X-Api-Key'));
        self::assertArrayHasKey('xapikey', Configuration::create(logDirectory: 'logs')->sensitiveKeyMap);
    }

    public function testCustomSensitiveKeysMergeWithDefaults(): void
    {
        $map = Configuration::create(
            logDirectory: 'logs',
            sensitiveKeys: ['payment-token', 'iban'],
        )->sensitiveKeyMap;

        self::assertArrayHasKey('paymenttoken', $map);
        self::assertArrayHasKey('iban', $map);
        self::assertArrayHasKey('password', $map, 'defaults must survive the merge');
    }
}
