<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Writer;

use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Exception\TracingException;
use Golovanov\Traceloom\TraceEvent;

/**
 * Appends events to date-based JSONL shards.
 *
 * The file handle is opened once and reused; that, not the lock, is what made the
 * old per-event open()/stat()/close() cycle expensive. Each append still takes an
 * exclusive flock on the handle, because O_APPEND is only atomic on POSIX: the
 * Windows CRT emulates it as seek-then-write, and concurrent writers there lose
 * roughly a third of their lines. On a persistent handle the lock is free
 * (measured: 7.4us with it, 8.0us without), so there is nothing to trade away.
 *
 * The directory lock is separate, and is taken only when a shard has to be
 * selected, created or rotated.
 *
 * Because each process caches the file size and only re-stats periodically,
 * maxFileBytes is a soft limit: with N concurrent writers a shard may overshoot
 * before someone notices and rotates.
 */
final class JsonlFileWriter implements WriterInterface
{
    private const LOCK_FILE = '.traceloom.lock';

    /**
     * How many appends may happen before the cached size is re-checked against the
     * real file. Bounds how far concurrent writers can overshoot maxFileBytes.
     */
    private const RESYNC_EVERY_WRITES = 128;

    /** @var resource|null */
    private $handle = null;

    private ?string $currentPath = null;
    private ?string $currentDate = null;
    private int $currentIndex = 0;
    private int $currentSize = 0;
    private int $writesSinceResync = 0;
    private bool $retentionApplied = false;

    public function __construct(private readonly Configuration $configuration)
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function write(TraceEvent $event): void
    {
        $line = $this->encodeLine($event);
        $bytes = strlen($line);
        $date = $event->utcDate();

        if ($this->needsResync($bytes)) {
            $this->resyncSize();
        }

        if ($this->needsRotation($date, $bytes)) {
            $this->rotate($date, $bytes);
        }

        $handle = $this->handle;
        $path = $this->currentPath;

        if ($handle === null || $path === null) {
            throw new TracingException('Log file is not open.');
        }

        if (!flock($handle, LOCK_EX)) {
            throw new TracingException('Unable to lock log file: ' . $path);
        }

        try {
            $this->writeAll($handle, $line, $path);

            if (!fflush($handle)) {
                throw new TracingException('Unable to flush log file: ' . $path);
            }
        } finally {
            flock($handle, LOCK_UN);
        }

        $this->currentSize += $bytes;
        $this->writesSinceResync++;
    }

    public function flush(): void
    {
        if ($this->handle !== null) {
            fflush($this->handle);
        }
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        fflush($this->handle);
        fclose($this->handle);

        $this->handle = null;
        $this->currentPath = null;
        $this->currentDate = null;
        $this->currentSize = 0;
        $this->writesSinceResync = 0;
    }

    private function needsResync(int $bytes): bool
    {
        if ($this->handle === null) {
            return false;
        }

        return $this->writesSinceResync >= self::RESYNC_EVERY_WRITES
            || $this->currentSize + $bytes > $this->configuration->maxFileBytes;
    }

    /**
     * Picks up bytes appended by other processes since our last check.
     */
    private function resyncSize(): void
    {
        if ($this->handle === null) {
            return;
        }

        $stat = fstat($this->handle);

        if ($stat !== false) {
            $this->currentSize = $stat['size'];
        }

        $this->writesSinceResync = 0;
    }

    private function needsRotation(string $date, int $bytes): bool
    {
        if ($this->handle === null || $this->currentDate !== $date) {
            return true;
        }

        // A fresh shard always accepts one record: Configuration clamps
        // maxRecordBytes to maxFileBytes, so this cannot loop forever.
        return $this->currentSize > 0
            && $this->currentSize + $bytes > $this->configuration->maxFileBytes;
    }

    private function rotate(string $date, int $bytes): void
    {
        $directory = $this->configuration->logDirectory;
        $this->ensureDirectory($directory);

        $lock = $this->open($directory . DIRECTORY_SEPARATOR . self::LOCK_FILE, 'c+b');

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new TracingException('Unable to lock log directory.');
            }

            $previousDate = $this->currentDate;
            $this->close();

            // Same day: keep scanning from the shard we already know about.
            // New day: locate the highest existing shard once.
            $index = $previousDate === $date
                ? $this->currentIndex
                : $this->discoverShardIndex($directory, $date);

            [$path, $size] = $this->selectShard($directory, $date, $index, $bytes);

            $existed = is_file($path);
            $handle = $this->open($path, 'ab');

            if (!$existed) {
                @chmod($path, $this->configuration->fileMode);
            }

            $this->handle = $handle;
            $this->currentPath = $path;
            $this->currentDate = $date;
            $this->currentSize = $size;
            $this->writesSinceResync = 0;

            $this->applyRetention($directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{0: string, 1: int} Path and its current size.
     */
    private function selectShard(string $directory, string $date, int $index, int $bytes): array
    {
        $maxFileBytes = $this->configuration->maxFileBytes;

        for (; ; $index++) {
            $path = $this->shardPath($directory, $date, $index);
            $size = $this->fileSize($path);

            if ($size === 0 || $size + $bytes <= $maxFileBytes) {
                $this->currentIndex = $index;

                return [$path, $size];
            }
        }
    }

    /**
     * Finds the highest existing shard for a date with one glob() instead of
     * walking every shard with a stat() on every single event.
     */
    private function discoverShardIndex(string $directory, string $date): int
    {
        $files = glob($directory . DIRECTORY_SEPARATOR . $date . '*.jsonl');

        if ($files === false || $files === []) {
            return 0;
        }

        $highest = 0;

        foreach ($files as $file) {
            $name = basename($file, '.jsonl');

            if ($name === $date) {
                continue;
            }

            if (preg_match('/^' . preg_quote($date, '/') . '-(\d+)$/', $name, $matches) === 1) {
                $highest = max($highest, (int)$matches[1]);
            }
        }

        return $highest;
    }

    private function shardPath(string $directory, string $date, int $index): string
    {
        $suffix = $index === 0 ? '' : '-' . $index;

        return $directory . DIRECTORY_SEPARATOR . $date . $suffix . '.jsonl';
    }

    /**
     * Runs once per process, on the first rotation, while the lock is held.
     */
    private function applyRetention(string $directory): void
    {
        $days = $this->configuration->retentionDays;

        if ($days === 0 || $this->retentionApplied) {
            return;
        }

        $this->retentionApplied = true;

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d');

        $files = glob($directory . DIRECTORY_SEPARATOR . '*.jsonl');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $date = substr(basename($file), 0, 10);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function encodeLine(TraceEvent $event): string
    {
        try {
            $json = $this->encode($event->toArray());
        } catch (TracingException $exception) {
            // The payload could not be encoded. The event itself still happened,
            // and a timeline with a placeholder beats a hole in the timeline.
            $json = $this->encode($event->toDegradedArray($exception->getMessage()));
        }

        $maxRecordBytes = $this->configuration->maxRecordBytes;

        if (strlen($json) + 1 > $maxRecordBytes) {
            $json = $this->encode($event->toDegradedArray(
                'record_too_large: ' . (strlen($json) + 1) . ' bytes exceeds ' . $maxRecordBytes,
            ));
        }

        return $json . "\n";
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
            throw new TracingException($exception->getMessage(), 0, $exception);
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

        $mode = $this->configuration->directoryMode;

        if (!mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new TracingException('Unable to create log directory: ' . $directory);
        }

        // mkdir()'s mode is masked by umask; force the configured mode back.
        @chmod($directory, $mode);
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
