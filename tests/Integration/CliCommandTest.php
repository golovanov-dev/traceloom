<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Tests\Integration;

use Golovanov\Traceloom\Tests\TestSupport\TempDirectory;
use Golovanov\Traceloom\Tracer;
use PHPUnit\Framework\TestCase;

final class CliCommandTest extends TestCase
{
    private ?string $tempDirectory = null;

    protected function tearDown(): void
    {
        if ($this->tempDirectory !== null) {
            TempDirectory::remove($this->tempDirectory);
        }
    }

    public function testShowsTraceTimeline(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom-cli');
        $tracer = Tracer::fromDirectory($this->tempDirectory);
        $trace = $tracer->start('cli-trace-123');

        $trace->event('request_start');
        usleep(1000);
        $trace->event('request_end');
        $tracer->close();

        $result = $this->eventtrace('show', 'cli-trace-123', '--dir=' . $this->tempDirectory);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('Trace: cli-trace-123', $result['stdout']);
        self::assertStringContainsString('request_start', $result['stdout']);
        self::assertStringContainsString('request_end', $result['stdout']);
        self::assertStringContainsString('Total duration:', $result['stdout']);
    }

    public function testReturnsNotFoundExitCode(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom-cli');

        $result = $this->eventtrace('show', 'missing-trace-id', '--dir=' . $this->tempDirectory);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString('Trace not found', $result['stdout']);
    }

    /**
     * A log file is untrusted input: event names may carry data from a request.
     * Raw ESC bytes reaching an operator's terminal let an attacker forge output.
     */
    public function testEscapesAnsiSequencesFromLogFiles(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom-cli');
        $line = json_encode([
            'timestamp' => '2026-07-10T10:00:00.000000Z',
            'trace_id' => 'ansi-trace-id',
            'event' => "\033[2J\033[31mFAKE-ALERT\033[0m",
            'sequence' => 1,
            'elapsed_ms' => 0,
            'data' => [],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->tempDirectory . DIRECTORY_SEPARATOR . '2026-07-10.jsonl', $line . "\n");

        $result = $this->eventtrace('show', 'ansi-trace-id', '--dir=' . $this->tempDirectory);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringNotContainsString("\033", $result['stdout']);
        self::assertStringContainsString('\x1B', $result['stdout']);
        self::assertStringContainsString('FAKE-ALERT', $result['stdout']);
    }

    public function testAggregatesMalformedLineWarnings(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom-cli');
        $path = $this->tempDirectory . DIRECTORY_SEPARATOR . '2026-07-10.jsonl';

        $good = json_encode([
            'timestamp' => '2026-07-10T10:00:00.000000Z',
            'trace_id' => 'broken-file-trace',
            'event' => 'ok',
            'sequence' => 1,
            'elapsed_ms' => 0,
            'data' => [],
        ], JSON_THROW_ON_ERROR);

        // Damaged lines still have to mention the trace id, or the prefilter skips
        // them before they can be counted as malformed.
        $garbage = str_repeat("{\"trace_id\":\"broken-file-trace\" TRUNCATED\n", 3);

        file_put_contents($path, $good . "\n" . $garbage);

        $result = $this->eventtrace('show', 'broken-file-trace', '--dir=' . $this->tempDirectory);

        self::assertSame(0, $result['exitCode']);
        self::assertSame(1, substr_count($result['stderr'], 'malformed'), 'one summary line, not one per bad line');
        self::assertStringContainsString('skipped 3 malformed', $result['stderr']);
    }

    /**
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function eventtrace(string ...$arguments): array
    {
        $command = array_values([PHP_BINARY, __DIR__ . '/../../bin/eventtrace', ...$arguments]);

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }
}
