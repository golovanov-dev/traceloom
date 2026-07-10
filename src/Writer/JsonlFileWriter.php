<?php

declare(strict_types=1);

namespace Golovanov\Writer;

use Golovanov\Configuration;
use Golovanov\Exception\TracingException;

final class JsonlFileWriter implements WriterInterface
{
    public function __construct(private readonly Configuration $configuration)
    {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function write(array $record): void
    {
        $line = $this->encode($record) . "\n";
        $directory = $this->configuration->logDirectory;

        $this->ensureDirectory($directory);

        $lock = $this->open($directory . DIRECTORY_SEPARATOR . '.traceloom.lock', 'c+b');

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new TracingException('Unable to lock log directory.');
            }

            $path = $this->resolvePath($directory, $record, strlen($line));
            $target = $this->open($path, 'ab');

            try {
                $this->writeAll($target, $line, $path);
                if (!fflush($target)) {
                    throw new TracingException('Unable to flush log file: ' . $path);
                }
            } finally {
                fclose($target);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function encode(array $record): string
    {
        try {
            return json_encode(
                $record,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new TracingException('Unable to encode trace event: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (file_exists($directory)) {
            throw new TracingException('Log path exists but is not a directory: ' . $directory);
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new TracingException('Unable to create log directory: ' . $directory);
        }
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw new TracingException('Unable to open file: ' . $path);
        }

        return $handle;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolvePath(string $directory, array $record, int $lineBytes): string
    {
        $date = $this->dateFromRecord($record);
        $maxFileBytes = $this->configuration->maxFileBytes;

        for ($index = 0; ; $index++) {
            $suffix = $index === 0 ? '' : '-' . $index;
            $path = $directory . DIRECTORY_SEPARATOR . $date . $suffix . '.jsonl';
            $size = $this->fileSize($path);

            if ($size === 0) {
                return $path;
            }

            if ($lineBytes <= $maxFileBytes && $size + $lineBytes <= $maxFileBytes) {
                return $path;
            }
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function dateFromRecord(array $record): string
    {
        $timestamp = $record['timestamp'] ?? null;

        if (is_string($timestamp) && preg_match('/^\d{4}-\d{2}-\d{2}/', $timestamp) === 1) {
            return substr($timestamp, 0, 10);
        }

        return gmdate('Y-m-d');
    }

    private function fileSize(string $path): int
    {
        clearstatcache(true, $path);

        if (!is_file($path)) {
            return 0;
        }

        $size = filesize($path);

        if ($size === false) {
            throw new TracingException('Unable to read log file size: ' . $path);
        }

        return $size;
    }

    /**
     * @param resource $handle
     */
    private function writeAll($handle, string $line, string $path): void
    {
        $offset = 0;
        $length = strlen($line);

        while ($offset < $length) {
            $written = fwrite($handle, substr($line, $offset));

            if ($written === false || $written === 0) {
                throw new TracingException('Unable to write log file: ' . $path);
            }

            $offset += $written;
        }
    }
}
