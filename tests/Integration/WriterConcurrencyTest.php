<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Tests\Integration;

use Golovanov\Traceloom\Tests\TestSupport\TempDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The hot write path deliberately holds no lock: it relies on O_APPEND, which the
 * kernel serializes per write() so concurrent appends cannot interleave. These tests
 * are what makes that claim more than a comment.
 */
final class WriterConcurrencyTest extends TestCase
{
    private const WORKERS = 4;
    private const EVENTS_PER_WORKER = 500;

    private ?string $tempDirectory = null;

    protected function tearDown(): void
    {
        if ($this->tempDirectory !== null) {
            TempDirectory::remove($this->tempDirectory);
        }
    }

    public function testConcurrentAppendsNeverInterleavePartialLines(): void
    {
        $this->tempDirectory = $directory = TempDirectory::create('traceloom-concurrency');

        $this->runWorkers($directory, maxFileBytes: 1024 * 1024);

        $lines = $this->readAllLines($directory);

        self::assertCount(self::WORKERS * self::EVENTS_PER_WORKER, $lines);

        $perWorker = array_fill(0, self::WORKERS, 0);

        foreach ($lines as $line) {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, 'every line must be a complete JSON object');
            $perWorker[(int)$decoded['data']['worker']]++;
        }

        foreach ($perWorker as $worker => $count) {
            self::assertSame(self::EVENTS_PER_WORKER, $count, "worker {$worker} lost events");
        }
    }

    public function testConcurrentRotationDoesNotLoseEvents(): void
    {
        $this->tempDirectory = $directory = TempDirectory::create('traceloom-rotation');

        // Small shards force every worker through the rotation path repeatedly.
        $this->runWorkers($directory, maxFileBytes: 4096);

        $lines = $this->readAllLines($directory);

        self::assertCount(self::WORKERS * self::EVENTS_PER_WORKER, $lines);
        self::assertGreaterThan(1, count(glob($directory . DIRECTORY_SEPARATOR . '*.jsonl') ?: []));

        foreach ($lines as $line) {
            self::assertIsArray(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    private function runWorkers(string $directory, int $maxFileBytes): void
    {
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'TestSupport'
            . DIRECTORY_SEPARATOR . 'concurrent-writer.php';

        $processes = [];

        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $process = proc_open(
                [
                    PHP_BINARY,
                    $script,
                    $directory,
                    (string)$worker,
                    (string)self::EVENTS_PER_WORKER,
                    (string)$maxFileBytes,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame(0, proc_close($process), 'worker failed: ' . (string)$stderr);
        }
    }

    /**
     * @return list<string>
     */
    private function readAllLines(string $directory): array
    {
        $files = glob($directory . DIRECTORY_SEPARATOR . '*.jsonl');
        self::assertIsArray($files);
        sort($files);

        $lines = [];

        foreach ($files as $file) {
            $fileLines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($fileLines);
            array_push($lines, ...$fileLines);
        }

        return $lines;
    }
}
