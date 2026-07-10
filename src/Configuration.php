<?php

declare(strict_types=1);

namespace Golovanov;

use Golovanov\Exception\ConfigurationException;

final class Configuration
{
    public const DEFAULT_MAX_FILE_BYTES = 50 * 1024 * 1024;
    public const DEFAULT_MAX_STRING_BYTES = 64 * 1024;
    public const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'passwd',
        'pwd',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'api_key',
        'secret',
        'client_secret',
    ];

    /**
     * @param list<string> $sensitiveKeys
     */
    private function __construct(
        public readonly string $logDirectory,
        public readonly int $maxFileBytes,
        public readonly int $maxStringBytes,
        public readonly array $sensitiveKeys,
        public readonly bool $failOnError,
        public readonly ?\Closure $onError,
    ) {
    }

    /**
     * @param list<string> $sensitiveKeys
     */
    public static function create(
        string $logDirectory,
        int $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
        int $maxStringBytes = self::DEFAULT_MAX_STRING_BYTES,
        array $sensitiveKeys = [],
        bool $failOnError = false,
        ?callable $onError = null,
    ): self {
        $logDirectory = rtrim(trim($logDirectory), "\\/");

        if ($logDirectory === '') {
            throw new ConfigurationException('Log directory must not be empty.');
        }

        if ($maxFileBytes <= 0) {
            throw new ConfigurationException('Max file size must be greater than zero.');
        }

        if ($maxStringBytes <= 0) {
            throw new ConfigurationException('Max string size must be greater than zero.');
        }

        $keys = self::normalizeSensitiveKeys([...self::DEFAULT_SENSITIVE_KEYS, ...$sensitiveKeys]);

        return new self(
            logDirectory: $logDirectory,
            maxFileBytes: $maxFileBytes,
            maxStringBytes: $maxStringBytes,
            sensitiveKeys: $keys,
            failOnError: $failOnError,
            onError: $onError === null ? null : \Closure::fromCallable($onError),
        );
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function normalizeSensitiveKeys(array $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            $key = strtolower(trim($key));
            if ($key !== '') {
                $normalized[$key] = $key;
            }
        }

        return array_values($normalized);
    }
}
