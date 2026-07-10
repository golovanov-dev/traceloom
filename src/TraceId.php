<?php

declare(strict_types=1);

namespace Golovanov;

final class TraceId
{
    private const PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function normalize(?string $traceId): string
    {
        $traceId = trim((string)$traceId);

        if ($traceId !== '' && preg_match(self::PATTERN, $traceId) === 1) {
            return $traceId;
        }

        return self::generate();
    }
}
