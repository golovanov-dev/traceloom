<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Support;

/**
 * Byte-level UTF-8 helpers.
 *
 * Deliberately does not use ext-mbstring: cutting on a code point boundary is a
 * four-line problem, and the package should stay dependency-free.
 */
final class Utf8
{
    public static function isValid(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    /**
     * Cuts $value to at most $maxBytes without splitting a multi-byte code point.
     *
     * Splitting one is not a cosmetic problem: the result is invalid UTF-8, and
     * json_encode() then rejects the entire record.
     *
     * Expects $value to be valid UTF-8 and strictly longer than $maxBytes.
     */
    public static function truncate(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $cut = $maxBytes;

        // Byte at $cut is the first dropped byte. While it is a continuation byte
        // (10xxxxxx) it belongs to a code point that started earlier, so walk back.
        while ($cut > 0 && (ord($value[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }

        return substr($value, 0, $cut);
    }
}
