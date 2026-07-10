<?php

declare(strict_types=1);

namespace Golovanov\Tests\Integration;

use Golovanov\Configuration;
use Golovanov\Trace;
use Golovanov\Tracer;
use Golovanov\Writer\JsonlFileWriter;
use Golovanov\Tests\TestSupport\FixedClock;
use Golovanov\Tests\TestSupport\TempDirectory;
use PHPUnit\Framework\TestCase;

final class TracingTest extends TestCase
{
    private ?string $tempDirectory = null;

    protected function tearDown(): void
    {
        if ($this->tempDirectory !== null) {
            TempDirectory::remove($this->tempDirectory);
        }
    }

    public function testWritesStructuredJsonlEventsWithSameTraceId(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $tracer = Tracer::create(logDirectory: $this->tempDirectory);

        $trace = $tracer->start();
        $trace->event('request_start', ['path' => '/orders']);
        $trace->event('auth_success', ['user_id' => 42]);

        $events = $this->readEvents($this->tempDirectory);

        self::assertCount(2, $events);
        self::assertSame($trace->id(), $events[0]['trace_id']);
        self::assertSame($trace->id(), $events[1]['trace_id']);
        self::assertSame('request_start', $events[0]['event']);
        self::assertSame('/orders', $events[0]['data']['path']);
        self::assertSame(1, $events[0]['sequence']);
        self::assertSame(2, $events[1]['sequence']);
    }

    public function testContinuesExistingTraceId(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $trace = Tracer::create(logDirectory: $this->tempDirectory)->start('incoming-trace-123');

        $trace->event('continued');

        self::assertSame('incoming-trace-123', $this->readEvents($this->tempDirectory)[0]['trace_id']);
    }

    public function testPreservesUnicodeAndSlashes(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $trace = Tracer::create(logDirectory: $this->tempDirectory)->start('trace-unicode');

        $trace->event('payload', [
            'message' => 'Привет',
            'url' => 'https://example.com/a/b',
        ]);

        $line = $this->readLines($this->tempDirectory)[0];

        self::assertStringContainsString('Привет', $line);
        self::assertStringContainsString('https://example.com/a/b', $line);
    }

    public function testRotatesIntoSequentialShardFiles(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $tracer = Tracer::create(
            logDirectory: $this->tempDirectory,
            maxFileBytes: 220,
        );
        $trace = $tracer->start('rotation-trace');

        $trace->event('first', ['body' => str_repeat('a', 40)]);
        $trace->event('second', ['body' => str_repeat('b', 40)]);
        $trace->event('third', ['body' => str_repeat('c', 40)]);

        $files = glob($this->tempDirectory . DIRECTORY_SEPARATOR . '*.jsonl');
        self::assertIsArray($files);
        $baseNames = array_map(static fn (string $file): string => basename($file), $files);

        self::assertGreaterThanOrEqual(2, count($files));
        self::assertContains('2026-07-10-1.jsonl', $baseNames);
    }

    public function testFailSafeModeReportsErrorsWithoutThrowing(): void
    {
        $errors = [];
        $this->tempDirectory = TempDirectory::create('traceloom');
        $filePath = $this->tempDirectory . DIRECTORY_SEPARATOR . 'not-a-directory';
        file_put_contents($filePath, 'x');

        $trace = Tracer::create(
            logDirectory: $filePath,
            onError: static function (\Throwable $exception) use (&$errors): void {
                $errors[] = $exception->getMessage();
            },
        )->start('fail-safe');

        $trace->event('will_not_break_app');

        self::assertNotEmpty($errors);
    }

    public function testStrictModeThrowsTracingErrors(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $filePath = $this->tempDirectory . DIRECTORY_SEPARATOR . 'not-a-directory';
        file_put_contents($filePath, 'x');

        $trace = Tracer::create(
            logDirectory: $filePath,
            failOnError: true,
        )->start('strict-mode');

        $this->expectException(\Throwable::class);

        $trace->event('will_throw');
    }

    public function testCanBeConstructedWithInjectedWriterAndClock(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $configuration = Configuration::create(logDirectory: $this->tempDirectory);
        $tracer = new Tracer(
            configuration: $configuration,
            writer: new JsonlFileWriter($configuration),
            clock: new FixedClock(new \DateTimeImmutable('2026-07-10T10:41:20.000000Z')),
        );

        $trace = $tracer->start('fixed-clock');
        $trace->event('first');
        $trace->event('second');

        $events = $this->readEvents($this->tempDirectory);

        self::assertSame('2026-07-10T10:41:20.001000Z', $events[0]['timestamp']);
        self::assertEquals(2.0, $events[1]['elapsed_ms']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEvents(string $directory): array
    {
        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $this->readLines($directory),
        );
    }

    /**
     * @return list<string>
     */
    private function readLines(string $directory): array
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
