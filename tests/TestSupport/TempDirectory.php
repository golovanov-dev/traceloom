<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Tests\TestSupport;

final class TempDirectory
{
    public static function create(string $prefix): string
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tests';

        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create temp root: ' . $root);
        }

        $base = rtrim($root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $prefix
            . '-'
            . bin2hex(random_bytes(6));

        if (!mkdir($base, 0775, true) && !is_dir($base)) {
            throw new \RuntimeException('Unable to create temp directory: ' . $base);
        }

        return $base;
    }

    public static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                self::remove($fullPath);
                continue;
            }

            unlink($fullPath);
        }

        rmdir($path);
    }
}
