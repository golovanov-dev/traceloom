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

    /**
     * Keys the sanitizer writes itself. A payload may not spell them: a record that
     * carried its own `_truncated` marker would be indistinguishable from one the
     * sanitizer produced, which is both a reporting bug and a way to forge a log.
     */
    private const RESERVED_KEY_PATTERN = '/^_+(binary|truncated|encoding_error|omitted_items)$/D';

    /** @var array<string, string> */
    private array $keyCache = [];

    /** @var list<string> */
    private readonly array $fragments;

    /**
     * Node budget left for the current event. Bounds the payload as a whole, which a
     * per-array cap cannot do: that alone would still permit maxArrayItems ** maxDepth
     * nodes. It is deliberately separate from maxArrayItems, which limits each array
     * on its own so that one long list cannot swallow its sibling fields.
     */
    private int $budget = 0;

    /**
     * @param array<string, true> $sensitiveKeyMap Canonicalized, see Configuration::canonicalizeKey().
     */
    public function __construct(
        private readonly int $maxStringBytes,
        private readonly int $maxArrayItems,
        private readonly int $maxPayloadNodes,
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
            maxPayloadNodes: $configuration->maxPayloadNodes,
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
        $this->budget = $this->maxPayloadNodes;

        /** @var array<string, mixed> $sanitized */
        $sanitized = $this->normalizeMap($payload, 1, new \SplObjectStorage());

        return $sanitized;
    }

    /**
     * A PHP list encodes to a JSON array, anything else to a JSON object, so the two
     * are bounded by different limits — exactly as in the JS and Go implementations.
     *
     * @param array<mixed> $data
     * @param \SplObjectStorage<object, null> $seen
     * @return array<mixed>
     */
    private function normalizeArray(array $data, int $depth, \SplObjectStorage $seen): array
    {
        return array_is_list($data)
            ? $this->normalizeList($data, $depth, $seen)
            : $this->normalizeMap($data, $depth, $seen);
    }

    /**
     * @param array<mixed> $data
     * @param \SplObjectStorage<object, null> $seen
     * @return array<mixed>
     */
    private function normalizeMap(array $data, int $depth, \SplObjectStorage $seen): array
    {
        $normalized = [];
        $omitted = 0;
        $remaining = count($data);

        foreach ($data as $key => $value) {
            if ($this->budget <= 0) {
                // Everything still unvisited is omitted, not just this one entry.
                $omitted = $remaining;
                break;
            }

            $remaining--;
            $this->budget--;

            if ($this->isSensitiveKey($key)) {
                $this->assign($normalized, $key, Configuration::REDACTED);
                continue;
            }

            $this->assign($normalized, $key, $this->normalizeValue($value, $depth + 1, $seen));
        }

        if ($omitted > 0) {
            $normalized['_omitted_items'] = $omitted;
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $data
     * @param \SplObjectStorage<object, null> $seen
     * @return list<mixed>
     */
    private function normalizeList(array $data, int $depth, \SplObjectStorage $seen): array
    {
        $normalized = [];
        $total = count($data);
        $omitted = 0;

        foreach ($data as $index => $value) {
            // Two independent limits: this array's own length, and the node budget for
            // the payload as a whole, which stops a wide or deeply nested bomb.
            if ($index >= $this->maxArrayItems || $this->budget <= 0) {
                $omitted = $total - $index;
                break;
            }

            $this->budget--;
            $normalized[] = $this->normalizeValue($value, $depth + 1, $seen);
        }

        if ($omitted > 0) {
            $normalized[] = ['_omitted_items' => $omitted];
        }

        return $normalized;
    }

    /**
     * Writes a key that came from untrusted input. A key spelling a sanitizer marker
     * gains a leading underscore, which is itself escaped on the way in, so the
     * mapping back stays unambiguous.
     *
     * @param array<mixed> $target
     */
    private function assign(array &$target, int|string $key, mixed $value): void
    {
        if (is_string($key) && preg_match(self::RESERVED_KEY_PATTERN, $key) === 1) {
            $target['_' . $key] = $value;

            return;
        }

        $target[$key] = $value;
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
