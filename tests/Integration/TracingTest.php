<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Tests\Integration;

use Golovanov\Traceloom\Clock\SystemClock;
use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Exception\TracingException;
use Golovanov\Traceloom\Tests\TestSupport\FixedClock;
use Golovanov\Traceloom\Tests\TestSupport\TempDirectory;
use Golovanov\Traceloom\TraceEvent;
use Golovanov\Traceloom\Tracer;
use Golovanov\Traceloom\Writer\JsonlFileWriter;
use Golovanov\Traceloom\Writer\WriterInterface;
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
        $tracer = Tracer::fromDirectory($this->tempDirectory);

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
        self::assertSame(0, $tracer->droppedEventCount());
        self::assertArrayNotHasKey('parent_trace_id', $events[0]);
    }

    public function testContinuesExistingTraceIdWhenExplicitlyTrusted(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $trace = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $this->tempDirectory,
            trustIncomingTraceId: true,
        ))->start('incoming-trace-123');

        $trace->event('continued');

        self::assertSame('incoming-trace-123', $this->readEvents($this->tempDirectory)[0]['trace_id']);
    }

    /**
     * The default must be safe: an inbound ID is attacker-controlled on a public
     * endpoint, and trusting it lets a client write into another request's trace.
     */
    public function testIncomingTraceIdIsUntrustedByDefault(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $tracer = Tracer::fromDirectory($this->tempDirectory);

        $trace = $tracer->start('attacker-supplied-id');
        $trace->event('webhook_received');

        $event = $this->readEvents($this->tempDirectory)[0];

        self::assertNotSame('attacker-supplied-id', $event['trace_id']);
        self::assertSame('attacker-supplied-id', $event['parent_trace_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string)$event['trace_id']);
    }

    public function testPreservesUnicodeAndSlashes(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $trace = Tracer::fromDirectory($this->tempDirectory)->start('trace-unicode');

        $trace->event('payload', [
            'message' => 'Привет',
            'url' => 'https://example.com/a/b',
        ]);

        $line = $this->readLines($this->tempDirectory)[0];

        self::assertStringContainsString('Привет', $line);
        self::assertStringContainsString('https://example.com/a/b', $line);
    }

    /**
     * Regression: a payload with any non-ASCII text longer than maxStringBytes used to
     * be dropped entirely, because truncation produced invalid UTF-8.
     */
    public function testLongMultiByteStringDoesNotDropTheEvent(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $tracer = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $this->tempDirectory,
            maxStringBytes: 11,
            failOnError: true,
        ));

        $tracer->start('utf8-trace')->event('cyr', ['msg' => str_repeat('привет', 5)]);

        $events = $this->readEvents($this->tempDirectory);

        self::assertCount(1, $events);
        self::assertTrue($events[0]['data']['msg']['_truncated']);
        self::assertSame(0, $tracer->droppedEventCount());
    }

    /**
     * U+2028/U+2029 are line terminators to some consumers but not to `\n`-splitting
     * ones, so a raw one in a record would let a payload forge a second JSONL line.
     * PHP's json_encode escapes them even under JSON_UNESCAPED_UNICODE — this locks
     * that in, because the format guarantee must not rest on an unstated default.
     */
    public function testUnicodeLineSeparatorsCannotBreakARecordInTwo(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $tracer = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $this->tempDirectory,
            failOnError: true,
        ));

        $tracer->start('u2028')->event('sep', ['text' => "before\u{2028}middle\u{2029}after"]);
        $tracer->close();

        $raw = (string)file_get_contents($this->logFiles($this->tempDirectory)[0]);

        self::assertStringNotContainsString("\u{2028}", $raw, 'must be escaped, not raw');
        self::assertStringNotContainsString("\u{2029}", $raw);
        self::assertCount(1, $this->readLines($this->tempDirectory), 'one event is one line');
        self::assertSame(
            "before\u{2028}middle\u{2029}after",
            $this->readEvents($this->tempDirectory)[0]['data']['text'],
            'the payload still round-trips',
        );
    }

    public function testBinaryPayloadDoesNotDropTheEvent(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $tracer = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $this->tempDirectory,
            failOnError: true,
        ));

        $tracer->start('binary-trace')->event('bin', ['blob' => "\xff\xfe"]);

        $events = $this->readEvents($this->tempDirectory);

        self::assertCount(1, $events);
        self::assertTrue($events[0]['data']['blob']['_binary']);
    }

    /**
     * Regression: a self-referencing payload killed the process with a fatal error
     * that no catch block could intercept.
     */
    public function testCircularPayloadDoesNotKillTheProcess(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $tracer = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $this->tempDirectory,
            failOnError: true,
        ));

        $object = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return $this;
            }
        };

        $tracer->start('circular')->event('orm_entity', ['entity' => $object]);

        $events = $this->readEvents($this->tempDirectory);

        self::assertCount(1, $events);
        self::assertSame('[CIRCULAR_REFERENCE]', $events[0]['data']['entity']);
    }

    public function testOversizedRecordIsDegradedRatherThanLost(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $maxFileBytes = 1024;
        $tracer = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $this->tempDirectory,
            maxFileBytes: $maxFileBytes,
            failOnError: true,
        ));

        $trace = $tracer->start('oversized');

        for ($i = 0; $i < 5; $i++) {
            $trace->event('big', ['blob' => str_repeat('a', 10_000)]);
        }

        $events = $this->readEvents($this->tempDirectory);

        self::assertCount(5, $events, 'no event may be lost');
        self::assertStringContainsString('record_too_large', (string)$events[0]['data']['_encoding_error']);

        // Regression: a line larger than maxFileBytes used to force a fresh shard per event.
        foreach ($this->logFiles($this->tempDirectory) as $file) {
            self::assertLessThanOrEqual($maxFileBytes, filesize($file), basename($file) . ' overflows the shard limit');
        }
    }

    public function testRotatesIntoSequentialShardFiles(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $tracer = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $this->tempDirectory,
            maxFileBytes: 220,
        ));
        $trace = $tracer->start('rotation-trace');

        $trace->event('first', ['body' => str_repeat('a', 40)]);
        $trace->event('second', ['body' => str_repeat('b', 40)]);
        $trace->event('third', ['body' => str_repeat('c', 40)]);

        $baseNames = array_map(
            static fn (string $file): string => basename($file),
            $this->logFiles($this->tempDirectory),
        );

        self::assertGreaterThanOrEqual(2, count($baseNames));
        self::assertContains(gmdate('Y-m-d') . '-1.jsonl', $baseNames);
    }

    public function testReopensTheHighestShardOnAFreshTracer(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $configuration = Configuration::create(logDirectory: $this->tempDirectory, maxFileBytes: 220);

        $first = Tracer::fromConfiguration($configuration);
        $trace = $first->start('shard-trace');
        $trace->event('a', ['body' => str_repeat('a', 40)]);
        $trace->event('b', ['body' => str_repeat('b', 40)]);
        $first->close();

        $shardsBefore = count($this->logFiles($this->tempDirectory));

        // A new process must continue in the existing shard, not restart at index 0.
        $second = Tracer::fromConfiguration($configuration);
        $second->start('shard-trace-2')->event('c', ['body' => str_repeat('c', 40)]);
        $second->close();

        self::assertGreaterThanOrEqual($shardsBefore, count($this->logFiles($this->tempDirectory)));
        self::assertCount(3, $this->readEvents($this->tempDirectory));
    }

    public function testFailSafeModeReportsErrorsWithoutThrowing(): void
    {
        $errors = [];
        $this->tempDirectory = TempDirectory::create('traceloom');
        $filePath = $this->tempDirectory . DIRECTORY_SEPARATOR . 'not-a-directory';
        file_put_contents($filePath, 'x');

        $tracer = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $filePath,
            onError: static function (\Throwable $exception) use (&$errors): void {
                $errors[] = $exception->getMessage();
            },
        ));

        $tracer->start('fail-safe')->event('will_not_break_app');

        self::assertNotEmpty($errors);
        self::assertSame(1, $tracer->droppedEventCount());
    }

    public function testStrictModeThrowsTracingErrors(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $filePath = $this->tempDirectory . DIRECTORY_SEPARATOR . 'not-a-directory';
        file_put_contents($filePath, 'x');

        $trace = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $filePath,
            failOnError: true,
        ))->start('strict-mode');

        $this->expectException(\Throwable::class);

        $trace->event('will_throw');
    }

    public function testEmptyEventNameAlwaysThrows(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $trace = Tracer::fromDirectory($this->tempDirectory)->start('empty-name');

        // failOnError is off, yet API misuse must still surface.
        $this->expectException(TracingException::class);

        $trace->event('   ');
    }

    public function testStripsControlCharactersFromEventNames(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $trace = Tracer::fromDirectory($this->tempDirectory)->start('ansi-name');

        $trace->event("webhook\033[31m_received");

        $events = $this->readEvents($this->tempDirectory);

        self::assertSame('webhook[31m_received', $events[0]['event']);
        self::assertStringNotContainsString("\033", $this->readLines($this->tempDirectory)[0]);
    }

    /**
     * A failed write must leave a GAP in the sequence rather than renumbering around
     * the loss. droppedEventCount() dies with the process; the file does not, so the
     * gap is the only durable signal to whoever reads the JSONL later.
     *
     * 0.2.0 briefly renumbered instead, which made the loss invisible in the file.
     */
    public function testFailedWriteLeavesAGapInTheSequence(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');

        $writer = new class implements WriterInterface {
            /** @var list<int> */
            public array $written = [];
            private int $calls = 0;

            public function write(TraceEvent $event): void
            {
                $this->calls++;

                if ($this->calls === 2) {
                    throw new \RuntimeException('disk full');
                }

                $this->written[] = $event->sequence;
            }

            public function flush(): void
            {
            }

            public function close(): void
            {
            }
        };

        $tracer = new Tracer(
            Configuration::create(logDirectory: $this->tempDirectory),
            $writer,
            new SystemClock(),
        );

        $trace = $tracer->start('sequence');

        for ($i = 0; $i < 4; $i++) {
            $trace->event('e');
        }

        self::assertSame([1, 3, 4], $writer->written, 'sequence 2 was lost and must stay missing');
        self::assertSame(1, $tracer->droppedEventCount());
    }

    public function testElapsedMsUsesTheMonotonicClock(): void
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
        $tracer->close();

        $events = $this->readEvents($this->tempDirectory);

        self::assertSame('2026-07-10T10:41:20.001000Z', $events[0]['timestamp']);
        self::assertEqualsWithDelta(1.0, $events[0]['elapsed_ms'], 0.001);
        self::assertEqualsWithDelta(2.0, $events[1]['elapsed_ms'], 0.001);
    }

    public function testRetentionRemovesExpiredShards(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $stale = $this->tempDirectory . DIRECTORY_SEPARATOR . '2020-01-01.jsonl';
        $staleShard = $this->tempDirectory . DIRECTORY_SEPARATOR . '2020-01-01-3.jsonl';
        file_put_contents($stale, "{}\n");
        file_put_contents($staleShard, "{}\n");

        $tracer = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $this->tempDirectory,
            retentionDays: 7,
        ));
        $tracer->start('retention')->event('now');
        $tracer->close();

        self::assertFileDoesNotExist($stale);
        self::assertFileDoesNotExist($staleShard);
        self::assertCount(1, $this->readEvents($this->tempDirectory));
    }

    /**
     * Retention used to match "starts with a date, ends with .jsonl", which also
     * matched files the library never wrote. Deleting a user's own data is not an
     * acceptable side effect of a log rotation policy.
     */
    public function testRetentionOnlyDeletesItsOwnShardNames(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $foreign = [
            '2020-01-01-backup.jsonl',
            '2020-01-02-export-final.jsonl',
            '2020-01-03.jsonl.bak',
            'notes.jsonl',
        ];

        foreach ($foreign as $name) {
            file_put_contents($this->tempDirectory . DIRECTORY_SEPARATOR . $name, "not ours\n");
        }

        $tracer = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $this->tempDirectory,
            retentionDays: 7,
        ));
        $tracer->start('retention')->event('now');
        $tracer->close();

        foreach ($foreign as $name) {
            self::assertFileExists(
                $this->tempDirectory . DIRECTORY_SEPARATOR . $name,
                $name . ' does not follow the shard naming scheme and must be left alone',
            );
        }
    }

    /**
     * close() is terminal. Reopening for a late event made shutdown non-deterministic,
     * leaked the handle, and reported success to a caller that had stopped tracing.
     */
    public function testCloseIsTerminal(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $tracer = Tracer::fromDirectory($this->tempDirectory);
        $trace = $tracer->start('closed');

        $trace->event('before_close');
        $tracer->close();
        $trace->event('after_close');

        self::assertCount(1, $this->readEvents($this->tempDirectory));
        self::assertSame(1, $tracer->droppedEventCount(), 'the late event is dropped, not written');
    }

    /**
     * A discarded payload is not a dropped event, but it is still a loss, and it must
     * be visible: without a counter an application whose events all exceed
     * maxRecordBytes would see droppedEventCount() == 0 and think tracing was healthy.
     */
    public function testDegradedPayloadIsCountedAndReported(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $reported = [];

        $tracer = Tracer::fromConfiguration(Configuration::create(
            logDirectory: $this->tempDirectory,
            maxFileBytes: 4096,
            maxRecordBytes: 1024,
            onError: static function (\Throwable $e) use (&$reported): void {
                $reported[] = $e->getMessage();
            },
        ));

        $tracer->start('degraded')->event('big', ['blob' => str_repeat('a', 5000)]);
        $tracer->close();

        $event = $this->readEvents($this->tempDirectory)[0];

        self::assertStringContainsString('record_too_large', (string)$event['data']['_encoding_error']);
        self::assertSame(0, $tracer->droppedEventCount(), 'the event itself survived');
        self::assertSame(1, $tracer->degradedEventCount());
        self::assertCount(1, $reported);
        self::assertStringContainsString('was discarded', $reported[0]);
    }

    public function testCreatesLogFilesWithRestrictivePermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        $this->tempDirectory = TempDirectory::create('traceloom');
        // The writer must create the directory itself for it to own the mode.
        $logDirectory = $this->tempDirectory . DIRECTORY_SEPARATOR . 'logs';

        $tracer = Tracer::fromConfiguration(Configuration::create(logDirectory: $logDirectory));
        $tracer->start('perms')->event('created');
        $tracer->close();

        $file = $this->logFiles($logDirectory)[0];

        self::assertSame(0750, fileperms($logDirectory) & 0777, 'log directory must not be world-readable');
        self::assertSame(0640, fileperms($file) & 0777, 'log file must not be world-readable');
    }

    /**
     * @return list<string>
     */
    private function logFiles(string $directory): array
    {
        $files = glob($directory . DIRECTORY_SEPARATOR . '*.jsonl');
        self::assertIsArray($files);
        sort($files);

        return array_values($files);
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
        $lines = [];

        foreach ($this->logFiles($directory) as $file) {
            $fileLines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($fileLines);
            array_push($lines, ...$fileLines);
        }

        return $lines;
    }
}
