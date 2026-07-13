<?php

declare(strict_types=1);

namespace Golovanov\Traceloom;

use Golovanov\Traceloom\Exception\ConfigurationException;

final class Configuration
{
    public const DEFAULT_MAX_FILE_BYTES = 50 * 1024 * 1024;
    public const DEFAULT_MAX_STRING_BYTES = 64 * 1024;
    public const DEFAULT_MAX_RECORD_BYTES = 256 * 1024;
    public const DEFAULT_MAX_ARRAY_ITEMS = 1000;
    public const DEFAULT_MAX_PAYLOAD_NODES = 10000;
    public const DEFAULT_MAX_KEY_BYTES = 256;
    public const DEFAULT_MAX_DEPTH = 16;

    /**
     * A truncated key carries a 17-byte digest suffix, so anything below this leaves
     * no room for the key itself.
     */
    public const MIN_MAX_KEY_BYTES = 32;
    public const DEFAULT_DIRECTORY_MODE = 0750;
    public const DEFAULT_FILE_MODE = 0640;

    public const REDACTED = '[REDACTED]';

    /**
     * Substrings matched against canonicalized keys in non-strict mode. Kept free of
     * short generic words such as `auth` (would redact `author`) and `key` (would
     * redact `keyboard`, `monkey`, `key_count`); those spellings are covered by the
     * exact list below instead.
     *
     * Kept in step with the JS and Go implementations: the same payload must be masked
     * the same way whichever one writes the log. `jwt` is the one deliberate addition
     * ahead of them — no English word contains it, so it carries no false-positive
     * risk, and without it `jwt_value` reaches the log in clear text. JS and Go should
     * adopt it.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'apikey',
        'privatekey',
        'credential',
        'authorization',
        'cookie',
        'session',
        'bearer',
        'signature',
        'jwt',
    ];

    /** @var list<string> */
    private const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'passwd',
        'pwd',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'proxy_authorization',
        'auth',
        'bearer',
        'jwt',
        'cookie',
        'set_cookie',
        'api_key',
        'x_api_key',
        'access_key',
        'secret_key',
        'secret',
        'client_secret',
        'private_key',
        'credentials',
        'signature',
        'session',
        'session_id',
        'csrf',
        'csrf_token',
    ];

    /**
     * @param array<string, true> $sensitiveKeyMap Canonicalized keys, see canonicalizeKey().
     */
    private function __construct(
        public readonly string $logDirectory,
        public readonly int $maxFileBytes,
        public readonly int $maxStringBytes,
        public readonly int $maxRecordBytes,
        public readonly int $maxArrayItems,
        public readonly int $maxPayloadNodes,
        public readonly int $maxKeyBytes,
        public readonly int $maxDepth,
        public readonly array $sensitiveKeyMap,
        public readonly bool $strictSensitiveKeys,
        public readonly int $directoryMode,
        public readonly int $fileMode,
        public readonly int $retentionDays,
        public readonly bool $trustIncomingTraceId,
        public readonly bool $failOnError,
        public readonly ?\Closure $onError,
    ) {
    }

    /**
     * @param list<string> $sensitiveKeys Merged with the built-in defaults.
     */
    public static function create(
        string $logDirectory,
        int $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
        int $maxStringBytes = self::DEFAULT_MAX_STRING_BYTES,
        int $maxRecordBytes = self::DEFAULT_MAX_RECORD_BYTES,
        int $maxArrayItems = self::DEFAULT_MAX_ARRAY_ITEMS,
        int $maxPayloadNodes = self::DEFAULT_MAX_PAYLOAD_NODES,
        int $maxKeyBytes = self::DEFAULT_MAX_KEY_BYTES,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        array $sensitiveKeys = [],
        bool $strictSensitiveKeys = false,
        int $directoryMode = self::DEFAULT_DIRECTORY_MODE,
        int $fileMode = self::DEFAULT_FILE_MODE,
        int $retentionDays = 0,
        bool $trustIncomingTraceId = false,
        bool $failOnError = false,
        ?callable $onError = null,
    ): self {
        $logDirectory = self::normalizeDirectory($logDirectory);

        self::assertPositive($maxFileBytes, 'Max file size');
        self::assertPositive($maxStringBytes, 'Max string size');
        self::assertPositive($maxRecordBytes, 'Max record size');
        self::assertPositive($maxArrayItems, 'Max array items');
        self::assertPositive($maxPayloadNodes, 'Max payload nodes');
        self::assertPositive($maxDepth, 'Max depth');

        if ($maxKeyBytes < self::MIN_MAX_KEY_BYTES) {
            throw new ConfigurationException(
                'Max key size must be at least ' . self::MIN_MAX_KEY_BYTES
                . ' bytes to leave room for the collision-avoiding digest.',
            );
        }

        if ($retentionDays < 0) {
            throw new ConfigurationException('Retention days must not be negative.');
        }

        // Clamped rather than rejected: a small maxFileBytes is a legitimate choice, and
        // it should tighten the record limit instead of invalidating the whole config.
        // Keeping maxRecordBytes <= maxFileBytes is what guarantees a single event can
        // never overflow a shard, so rotation cannot degrade into one file per event.
        $maxRecordBytes = min($maxRecordBytes, $maxFileBytes);
        $maxStringBytes = min($maxStringBytes, $maxRecordBytes);

        return new self(
            logDirectory: $logDirectory,
            maxFileBytes: $maxFileBytes,
            maxStringBytes: $maxStringBytes,
            maxRecordBytes: $maxRecordBytes,
            maxArrayItems: $maxArrayItems,
            maxPayloadNodes: $maxPayloadNodes,
            maxKeyBytes: $maxKeyBytes,
            maxDepth: $maxDepth,
            sensitiveKeyMap: self::buildSensitiveKeyMap([...self::DEFAULT_SENSITIVE_KEYS, ...$sensitiveKeys]),
            strictSensitiveKeys: $strictSensitiveKeys,
            directoryMode: $directoryMode,
            fileMode: $fileMode,
            retentionDays: $retentionDays,
            trustIncomingTraceId: $trustIncomingTraceId,
            failOnError: $failOnError,
            onError: $onError === null ? null : \Closure::fromCallable($onError),
        );
    }

    /**
     * Reduces a payload key to a comparable form so that `X-Api-Key`, `api_key`,
     * `apiKey` and `APIKEY` all collapse to `apikey`.
     */
    public static function canonicalizeKey(string $key): string
    {
        $lowered = strtolower($key);
        $canonical = preg_replace('/[^a-z0-9]+/', '', $lowered);

        return $canonical ?? '';
    }

    /**
     * @return list<string>
     */
    public static function sensitiveKeyFragments(): array
    {
        return self::SENSITIVE_KEY_FRAGMENTS;
    }

    private static function normalizeDirectory(string $logDirectory): string
    {
        $logDirectory = trim($logDirectory);

        if ($logDirectory === '') {
            throw new ConfigurationException('Log directory must not be empty.');
        }

        $normalized = rtrim($logDirectory, "\\/");

        // rtrim() eats the whole path for filesystem roots: "/" and "C:\".
        if ($normalized === '') {
            return DIRECTORY_SEPARATOR;
        }

        if (preg_match('/^[A-Za-z]:$/', $normalized) === 1) {
            return $normalized . DIRECTORY_SEPARATOR;
        }

        return $normalized;
    }

    private static function assertPositive(int $value, string $label): void
    {
        if ($value <= 0) {
            throw new ConfigurationException($label . ' must be greater than zero.');
        }
    }

    /**
     * @param list<string> $keys
     * @return array<string, true>
     */
    private static function buildSensitiveKeyMap(array $keys): array
    {
        $map = [];

        foreach ($keys as $key) {
            $canonical = self::canonicalizeKey($key);

            if ($canonical !== '') {
                $map[$canonical] = true;
            }
        }

        return $map;
    }
}
