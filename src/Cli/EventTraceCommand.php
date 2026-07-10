<?php

declare(strict_types=1);

namespace Golovanov\Cli;

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

        $traceId = $argv[2] ?? '';
        $directory = $this->optionValue($argv, '--dir') ?? 'logs';

        if (trim($traceId) === '') {
            fwrite(STDERR, "Trace ID is required.\n\n");
            $this->printUsage(STDERR);
            return 1;
        }

        if (!is_dir($directory)) {
            fwrite(STDERR, "Log directory does not exist: {$directory}\n");
            return 1;
        }

        $events = $this->findEvents($directory, $traceId);

        if ($events === []) {
            fwrite(STDOUT, "Trace not found: {$traceId}\n");
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
     * @return list<array<string, mixed>>
     */
    private function findEvents(string $directory, string $traceId): array
    {
        $pattern = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . '*.jsonl';
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        sort($files);
        $events = [];

        foreach ($files as $file) {
            $handle = fopen($file, 'rb');

            if ($handle === false) {
                fwrite(STDERR, "Warning: unable to read {$file}\n");
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode($line, true);

                if (!is_array($decoded)) {
                    fwrite(STDERR, "Warning: malformed JSONL line in {$file}\n");
                    continue;
                }

                if (($decoded['trace_id'] ?? null) === $traceId) {
                    /** @var array<string, mixed> $decoded */
                    $events[] = $decoded;
                }
            }

            fclose($handle);
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
        fwrite(STDOUT, "Trace: {$traceId}\n");

        $previousElapsed = null;
        $lastElapsed = 0.0;

        foreach ($events as $event) {
            $timestamp = $this->formatTime((string)($event['timestamp'] ?? ''));
            $name = (string)($event['event'] ?? 'unknown');
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

    private function formatTime(string $timestamp): string
    {
        if (preg_match('/T(\d{2}:\d{2}:\d{2}\.\d{3})/', $timestamp, $matches) === 1) {
            return $matches[1];
        }

        return $timestamp !== '' ? $timestamp : 'unknown-time';
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
