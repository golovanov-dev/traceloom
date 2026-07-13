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
     * Decodes the code point of a single, valid UTF-8 character.
     *
     * Exists so the package stays free of ext-mbstring (mb_ord) for what is a
     * handful of bit operations.
     */
    public static function ord(string $character): int
    {
        $bytes = array_map('ord', str_split($character));
        $length = count($bytes);

        return match ($length) {
            1 => $bytes[0],
            2 => (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F),
            3 => (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F),
            4 => (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12)
                | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F),
            default => 0xFFFD,
        };
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
