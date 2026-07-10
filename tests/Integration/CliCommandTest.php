<?php

declare(strict_types=1);

namespace Golovanov\Tests\Integration;

use Golovanov\Tracer;
use Golovanov\Tests\TestSupport\TempDirectory;
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
        $trace = Tracer::create(logDirectory: $this->tempDirectory)->start('cli-trace-123');

        $trace->event('request_start');
        usleep(1000);
        $trace->event('request_end');

        $command = [
            PHP_BINARY,
            __DIR__ . '/../../bin/eventtrace',
            'show',
            'cli-trace-123',
            '--dir=' . $this->tempDirectory,
        ];

        $result = $this->runCommand($command);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('Trace: cli-trace-123', $result['stdout']);
        self::assertStringContainsString('request_start', $result['stdout']);
        self::assertStringContainsString('request_end', $result['stdout']);
        self::assertStringContainsString('Total duration:', $result['stdout']);
    }

    /**
     * @param list<string> $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runCommand(array $command): array
    {
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
