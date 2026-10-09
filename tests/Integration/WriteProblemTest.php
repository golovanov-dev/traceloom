<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Tests\Integration;

use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Tests\TestSupport\FixedClock;
use Golovanov\Traceloom\Tests\TestSupport\TempDirectory;
use Golovanov\Traceloom\Writer\JsonlFileWriter;
use PHPUnit\Framework\TestCase;

final class WriteProblemTest extends TestCase
{
    private ?string $tempDirectory = null;

    /** @var list<string> */
    private array $readOnly = [];

    protected function tearDown(): void
    {
        foreach ($this->readOnly as $path) {
            @chmod($path, 0755);
        }

        if ($this->tempDirectory !== null) {
            TempDirectory::remove($this->tempDirectory);
        }
    }

    public function testMissingDirectoryIsWritableWhenItCanBeCreatedAndIsNotCreated(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $directory = $this->tempDirectory . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b';

        self::assertNull(JsonlFileWriter::writeProblem(Configuration::create(logDirectory: $directory)));
        self::assertFileDoesNotExist($this->tempDirectory . DIRECTORY_SEPARATOR . 'a');
    }

    public function testReportsAPathThatIsNotADirectory(): void
    {
        $this->tempDirectory = TempDirectory::create('traceloom');
        $path = $this->tempDirectory . DIRECTORY_SEPARATOR . 'not-a-directory';
        file_put_contents($path, 'x');

        self::assertSame(
            'Log path exists but is not a directory: ' . $path,
            JsonlFileWriter::writeProblem(Configuration::create(logDirectory: $path)),
        );
    }

    public function testReportsAnUnwritableLockFile(): void
    {
        $this->skipWhenPermissionsAreNotEnforced();
        $this->tempDirectory = TempDirectory::create('traceloom');
        $lock = $this->readOnlyFile('.traceloom.lock', '');

        self::assertSame(
            'Lock file is not readable and writable: ' . $lock,
            JsonlFileWriter::writeProblem(Configuration::create(logDirectory: $this->tempDirectory)),
        );
    }

    /**
     * 01:00 in Moscow is still the previous day in UTC, and the shard is named by UTC.
     */
    public function testChecksTheShardOfTheClocksUtcDate(): void
    {
        $this->skipWhenPermissionsAreNotEnforced();
        $this->tempDirectory = TempDirectory::create('traceloom');
        $configuration = Configuration::create(logDirectory: $this->tempDirectory);
        $this->readOnlyFile('2026-10-09.jsonl', "{}\n");

        self::assertNull(JsonlFileWriter::writeProblem($configuration, self::clock()));

        $shard = $this->readOnlyFile('2026-10-08.jsonl', "{}\n");

        self::assertSame(
            'Log file is not writable: ' . $shard,
            JsonlFileWriter::writeProblem($configuration, self::clock()),
        );
    }

    /**
     * The next write appends to the highest shard that still has room, as the writer
     * selects it: a full shard is skipped, a later one is checked.
     */
    public function testChecksTheShardTheNextWriteAppendsTo(): void
    {
        $this->skipWhenPermissionsAreNotEnforced();
        $this->tempDirectory = TempDirectory::create('traceloom');
        $configuration = Configuration::create(logDirectory: $this->tempDirectory, maxFileBytes: 1024);
        $this->readOnlyFile('2026-10-08.jsonl', str_repeat('x', 1024));

        self::assertNull(JsonlFileWriter::writeProblem($configuration, self::clock()), 'full shard is not written');

        $shard = $this->readOnlyFile('2026-10-08-1.jsonl', "{}\n");

        self::assertSame(
            'Log file is not writable: ' . $shard,
            JsonlFileWriter::writeProblem($configuration, self::clock()),
        );
    }

    public function testReportsAnUnwritableDirectory(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows does not enforce a read-only mode on directories.');
        }

        $this->skipWhenPermissionsAreNotEnforced();
        $this->tempDirectory = TempDirectory::create('traceloom');
        chmod($this->tempDirectory, 0555);
        $this->readOnly[] = $this->tempDirectory;
        $missing = $this->tempDirectory . DIRECTORY_SEPARATOR . 'logs';

        self::assertSame(
            'Log directory is not writable: ' . $this->tempDirectory,
            JsonlFileWriter::writeProblem(Configuration::create(logDirectory: $this->tempDirectory)),
        );
        self::assertSame(
            'Log directory cannot be created: ' . $missing,
            JsonlFileWriter::writeProblem(Configuration::create(logDirectory: $missing)),
        );
    }

    private static function clock(): FixedClock
    {
        return new FixedClock(new \DateTimeImmutable('2026-10-09 01:00:00', new \DateTimeZone('Europe/Moscow')));
    }

    private function readOnlyFile(string $name, string $contents): string
    {
        $path = (string)$this->tempDirectory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);
        chmod($path, 0444);
        $this->readOnly[] = $path;

        return $path;
    }

    private function skipWhenPermissionsAreNotEnforced(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can write a read-only file.');
        }
    }
}
