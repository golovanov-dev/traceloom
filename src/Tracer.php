<?php

declare(strict_types=1);

namespace Golovanov;

use Golovanov\Clock\ClockInterface;
use Golovanov\Clock\SystemClock;
use Golovanov\Sanitizer\PayloadSanitizer;
use Golovanov\Writer\JsonlFileWriter;
use Golovanov\Writer\WriterInterface;

final class Tracer
{
    private readonly PayloadSanitizer $sanitizer;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly WriterInterface $writer,
        private readonly ClockInterface $clock,
    ) {
        $this->sanitizer = new PayloadSanitizer($configuration);
    }

    /**
     * @param list<string> $sensitiveKeys
     */
    public static function create(
        Configuration|string $logDirectory,
        int $maxFileBytes = Configuration::DEFAULT_MAX_FILE_BYTES,
        int $maxStringBytes = Configuration::DEFAULT_MAX_STRING_BYTES,
        array $sensitiveKeys = [],
        bool $failOnError = false,
        ?callable $onError = null,
    ): self {
        $configuration = $logDirectory instanceof Configuration
            ? $logDirectory
            : Configuration::create(
                logDirectory: $logDirectory,
                maxFileBytes: $maxFileBytes,
                maxStringBytes: $maxStringBytes,
                sensitiveKeys: $sensitiveKeys,
                failOnError: $failOnError,
                onError: $onError,
            );

        return new self(
            configuration: $configuration,
            writer: new JsonlFileWriter($configuration),
            clock: new SystemClock(),
        );
    }

    public function start(?string $traceId = null): Trace
    {
        return new Trace(
            traceId: TraceId::normalize($traceId),
            configuration: $this->configuration,
            writer: $this->writer,
            sanitizer: $this->sanitizer,
            clock: $this->clock,
        );
    }
}
