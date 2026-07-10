<?php

declare(strict_types=1);

namespace Golovanov\Traceloom;

final class TraceId
{
    /**
     * The `D` modifier pins `$` to the true end of the subject. Without it, `$` also
     * matches before a trailing newline. trim() happens to remove that newline today,
     * but the pattern should not depend on its caller for correctness.
     */
    private const PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/D';

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return string|null The ID if it is well-formed, null otherwise.
     */
    public static function sanitize(?string $traceId): ?string
    {
        if ($traceId === null) {
            return null;
        }

        $traceId = trim($traceId);

        if ($traceId === '' || preg_match(self::PATTERN, $traceId) !== 1) {
            return null;
        }

        return $traceId;
    }

    public static function normalize(?string $traceId): string
    {
        return self::sanitize($traceId) ?? self::generate();
    }
}
