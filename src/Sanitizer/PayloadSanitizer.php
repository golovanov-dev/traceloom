<?php

declare(strict_types=1);

namespace Golovanov\Sanitizer;

use Golovanov\Configuration;

final class PayloadSanitizer
{
    /** @var array<string, true> */
    private readonly array $sensitiveKeyMap;

    public function __construct(private readonly Configuration $configuration)
    {
        $map = [];

        foreach ($configuration->sensitiveKeys as $key) {
            $map[strtolower($key)] = true;
        }

        $this->sensitiveKeyMap = $map;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        /** @var array<string, mixed> $sanitized */
        $sanitized = $this->normalizeArray($payload);

        return $sanitized;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function normalizeArray(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $normalized[$key] = Configuration::REDACTED;
                continue;
            }

            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->normalizeString($value);
        }

        if (is_array($value)) {
            return $this->normalizeArray($value);
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize());
        }

        if ($value instanceof \Stringable) {
            return $this->normalizeString((string)$value);
        }

        if (is_object($value)) {
            return '[UNSUPPORTED_TYPE: ' . $value::class . ']';
        }

        return '[UNSUPPORTED_TYPE: ' . get_debug_type($value) . ']';
    }

    /**
     * @return string|array{_truncated: true, size_bytes: int, preview: string}
     */
    private function normalizeString(string $value): string|array
    {
        $size = strlen($value);

        if ($size <= $this->configuration->maxStringBytes) {
            return $value;
        }

        return [
            '_truncated' => true,
            'size_bytes' => $size,
            'preview' => substr($value, 0, $this->configuration->maxStringBytes),
        ];
    }

    private function isSensitiveKey(int|string $key): bool
    {
        return is_string($key) && isset($this->sensitiveKeyMap[strtolower($key)]);
    }
}
