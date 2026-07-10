<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Cli;

final class EventTraceCommand
{
    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? '';

        if ($command !== 'show') {
            $this->printUsage(STDERR);
            return $command === '' || in_array($command, ['help', '--help', '-h'], true) ? 0 : 1;
        }

        $traceId = trim($argv[2] ?? '');
        $directory = $this->optionValue($argv, '--dir') ?? 'logs';

        if ($traceId === '') {
            fwrite(STDERR, "Trace ID is required.\n\n");
            $this->printUsage(STDERR);
            return 1;
        }

        if (!is_dir($directory)) {
            fwrite(STDERR, 'Log directory does not exist: ' . self::safe($directory) . "\n");
            return 1;
        }

        $events = $this->findEvents($directory, $traceId);

        if ($events === []) {
            fwrite(STDOUT, 'Trace not found: ' . self::safe($traceId) . "\n");
            return 2;
        }

        $this->printTimeline($traceId, $events);
        return 0;
    }

    /**
     * @param list<string> $argv
     */
    private function optionValue(array $argv, string $name): ?string
    {
        foreach ($argv as $index => $argument) {
            if ($argument === $name) {
                return $argv[$index + 1] ?? null;
            }

            if (str_starts_with($argument, $name . '=')) {
                return substr($argument, strlen($name) + 1);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function logFiles(string $directory): array
    {
        // scandir() rather than glob(): a directory path containing glob metacharacters
        // ("*", "?", "[") would otherwise be interpreted as a pattern.
        $entries = @scandir($directory);

        if ($entries === false) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if (str_ends_with($entry, '.jsonl')) {
                $files[] = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $entry;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findEvents(string $directory, string $traceId): array
    {
        $events = [];
        $malformed = 0;

        foreach ($this->logFiles($directory) as $file) {
            $handle = fopen($file, 'rb');

            if ($handle === false) {
                fwrite(STDERR, 'Warning: unable to read ' . self::safe($file) . "\n");
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                // The trace ID is a literal substring of any line that mentions it, and
                // this check is ~50x cheaper than decoding every line just to discard it.
                if (!str_contains($line, $traceId)) {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (!is_array($decoded)) {
                    $malformed++;
                    continue;
                }

                // The substring may have matched inside `data`; confirm the real field.
                if (($decoded['trace_id'] ?? null) === $traceId) {
                    /** @var array<string, mixed> $decoded */
                    $events[] = $decoded;
                }
            }

            fclose($handle);
        }

        if ($malformed > 0) {
            // A crashed process routinely leaves one partial line at EOF. Report the
            // count once instead of a line of stderr per damaged line.
            fwrite(STDERR, "Warning: skipped {$malformed} malformed JSONL line(s).\n");
        }

        usort(
            $events,
            static function (array $left, array $right): int {
                $time = strcmp((string)($left['timestamp'] ?? ''), (string)($right['timestamp'] ?? ''));

                if ($time !== 0) {
                    return $time;
                }

                return ((int)($left['sequence'] ?? 0)) <=> ((int)($right['sequence'] ?? 0));
            },
        );

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function printTimeline(string $traceId, array $events): void
    {
        fwrite(STDOUT, 'Trace: ' . self::safe($traceId) . "\n");

        $parent = $events[0]['parent_trace_id'] ?? null;

        if (is_string($parent)) {
            fwrite(STDOUT, 'Parent: ' . self::safe($parent) . "\n");
        }

        $previousElapsed = null;
        $lastElapsed = 0.0;

        foreach ($events as $event) {
            $timestamp = $this->formatTime((string)($event['timestamp'] ?? ''));
            $name = self::safe((string)($event['event'] ?? 'unknown'));
            $elapsed = is_numeric($event['elapsed_ms'] ?? null) ? (float)$event['elapsed_ms'] : null;
            $delta = '';

            if ($elapsed !== null) {
                $lastElapsed = $elapsed;

                if ($previousElapsed !== null) {
                    $deltaMs = max(0.0, $elapsed - $previousElapsed);
                    $delta = ' +' . $this->formatMilliseconds($deltaMs);
                }

                $previousElapsed = $elapsed;
            }

            fwrite(STDOUT, "{$timestamp} {$name}{$delta}\n");
        }

        fwrite(STDOUT, 'Total duration: ' . $this->formatMilliseconds($lastElapsed) . "\n");
    }

    /**
     * A log file is untrusted input for this command: it may hold event names built
     * from request data. Printing raw bytes would let an ESC sequence repaint, clear
     * or forge the operator's terminal.
     */
    private static function safe(string $value): string
    {
        $escaped = preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn (array $m): string => sprintf('\x%02X', ord($m[0])),
            $value,
        );

        if ($escaped === null) {
            return '[UNPRINTABLE]';
        }

        // Anything left that is not valid UTF-8 would still confuse the terminal.
        return preg_match('//u', $escaped) === 1 ? $escaped : '[UNPRINTABLE]';
    }

    private function formatTime(string $timestamp): string
    {
        if (preg_match('/T(\d{2}:\d{2}:\d{2}\.\d{3})/', $timestamp, $matches) === 1) {
            return $matches[1];
        }

        return $timestamp !== '' ? self::safe($timestamp) : 'unknown-time';
    }

    private function formatMilliseconds(float $milliseconds): string
    {
        $rounded = round($milliseconds, 3);

        if (abs($rounded - round($rounded)) < 0.001) {
            return (string)(int)round($rounded) . ' ms';
        }

        return rtrim(rtrim(number_format($rounded, 3, '.', ''), '0'), '.') . ' ms';
    }

    /**
     * @param resource $stream
     */
    private function printUsage($stream): void
    {
        fwrite(
            $stream,
            "Usage:\n"
            . "  eventtrace show <trace-id> --dir=logs\n",
        );
    }
}
