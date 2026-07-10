<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Sanitizer;

use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Support\Utf8;

final class PayloadSanitizer
{
    public const MAX_DEPTH_EXCEEDED = '[MAX_DEPTH_EXCEEDED]';
    public const CIRCULAR_REFERENCE = '[CIRCULAR_REFERENCE]';

    private const KEY_CACHE_LIMIT = 512;
    private const BINARY_PREVIEW_BYTES = 32;

    /** @var array<string, string> */
    private array $keyCache = [];

    /** @var list<string> */
    private readonly array $fragments;

    /**
     * Remaining array entries this event may keep. A per-array cap would still allow
     * maxArrayItems ** maxDepth nodes, so the budget spans the whole payload.
     */
    private int $budget = 0;

    /**
     * @param array<string, true> $sensitiveKeyMap Canonicalized, see Configuration::canonicalizeKey().
     */
    public function __construct(
        private readonly int $maxStringBytes,
        private readonly int $maxArrayItems,
        private readonly int $maxDepth,
        private readonly array $sensitiveKeyMap,
        private readonly bool $strictSensitiveKeys = false,
    ) {
        $this->fragments = Configuration::sensitiveKeyFragments();
    }

    public static function fromConfiguration(Configuration $configuration): self
    {
        return new self(
            maxStringBytes: $configuration->maxStringBytes,
            maxArrayItems: $configuration->maxArrayItems,
            maxDepth: $configuration->maxDepth,
            sensitiveKeyMap: $configuration->sensitiveKeyMap,
            strictSensitiveKeys: $configuration->strictSensitiveKeys,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        $this->budget = $this->maxArrayItems;

        /** @var array<string, mixed> $sanitized */
        $sanitized = $this->normalizeArray($payload, 1, new \SplObjectStorage());

        return $sanitized;
    }

    /**
     * @param array<mixed> $data
     * @param \SplObjectStorage<object, null> $seen
     * @return array<mixed>
     */
    private function normalizeArray(array $data, int $depth, \SplObjectStorage $seen): array
    {
        $normalized = [];
        $omitted = 0;

        foreach ($data as $key => $value) {
            if ($this->budget <= 0) {
                $omitted++;
                continue;
            }

            $this->budget--;

            if ($this->isSensitiveKey($key)) {
                $normalized[$key] = Configuration::REDACTED;
                continue;
            }

            $normalized[$key] = $this->normalizeValue($value, $depth + 1, $seen);
        }

        if ($omitted > 0) {
            $normalized['_omitted_items'] = $omitted;
        }

        return $normalized;
    }

    /**
     * @param \SplObjectStorage<object, null> $seen
     */
    private function normalizeValue(mixed $value, int $depth, \SplObjectStorage $seen): mixed
    {
        if ($value === null || is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            // NAN and INF are not representable in JSON and would abort the whole record.
            return is_finite($value) ? $value : (string)$value;
        }

        if (is_string($value)) {
            return $this->normalizeString($value);
        }

        if ($depth > $this->maxDepth) {
            return self::MAX_DEPTH_EXCEEDED;
        }

        if (is_array($value)) {
            return $this->normalizeArray($value, $depth, $seen);
        }

        if (is_object($value)) {
            return $this->normalizeObject($value, $depth, $seen);
        }

        return '[UNSUPPORTED_TYPE: ' . get_debug_type($value) . ']';
    }

    /**
     * @param \SplObjectStorage<object, null> $seen
     */
    private function normalizeObject(object $value, int $depth, \SplObjectStorage $seen): mixed
    {
        if ($seen->contains($value)) {
            return self::CIRCULAR_REFERENCE;
        }

        if (!$value instanceof \JsonSerializable && !$value instanceof \Stringable) {
            return '[UNSUPPORTED_TYPE: ' . $value::class . ']';
        }

        $seen->attach($value);

        try {
            // jsonSerialize() and __toString() are application code. A throw in there is
            // an application bug, not a tracing failure, and must not cost the whole event.
            try {
                $unwrapped = $value instanceof \JsonSerializable
                    ? $value->jsonSerialize()
                    : (string)$value;
            } catch (\Throwable $exception) {
                return '[SERIALIZATION_FAILED: ' . $value::class . ']';
            }

            return $this->normalizeValue($unwrapped, $depth, $seen);
        } finally {
            $seen->detach($value);
        }
    }

    /**
     * @return string|array{_truncated: true, size_bytes: int, preview: string}|array{_binary: true, size_bytes: int, preview: string}
     */
    private function normalizeString(string $value): string|array
    {
        $size = strlen($value);

        // Invalid UTF-8 anywhere in the payload makes json_encode() throw and would
        // otherwise take the entire event down with it.
        if (!Utf8::isValid($value)) {
            return [
                '_binary' => true,
                'size_bytes' => $size,
                'preview' => bin2hex(substr($value, 0, self::BINARY_PREVIEW_BYTES)),
            ];
        }

        if ($size <= $this->maxStringBytes) {
            return $value;
        }

        return [
            '_truncated' => true,
            'size_bytes' => $size,
            'preview' => Utf8::truncate($value, $this->maxStringBytes),
        ];
    }

    private function isSensitiveKey(int|string $key): bool
    {
        if (!is_string($key)) {
            return false;
        }

        $canonical = $this->canonicalize($key);

        if ($canonical === '') {
            return false;
        }

        if (isset($this->sensitiveKeyMap[$canonical])) {
            return true;
        }

        if ($this->strictSensitiveKeys) {
            return false;
        }

        foreach ($this->fragments as $fragment) {
            if (str_contains($canonical, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function canonicalize(string $key): string
    {
        if (isset($this->keyCache[$key])) {
            return $this->keyCache[$key];
        }

        $canonical = Configuration::canonicalizeKey($key);

        if (count($this->keyCache) < self::KEY_CACHE_LIMIT) {
            $this->keyCache[$key] = $canonical;
        }

        return $canonical;
    }
}
