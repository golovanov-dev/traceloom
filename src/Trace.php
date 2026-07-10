<?php

declare(strict_types=1);

namespace Golovanov\Traceloom;

use Golovanov\Traceloom\Clock\ClockInterface;
use Golovanov\Traceloom\Exception\TracingException;
use Golovanov\Traceloom\Sanitizer\PayloadSanitizer;
use Golovanov\Traceloom\Support\Utf8;
use Golovanov\Traceloom\Writer\WriterInterface;

final class Trace
{
    private const MAX_NAME_LENGTH = 128;
    private const INVALID_NAME = '[INVALID_EVENT_NAME]';

    private int $sequence = 0;
    private readonly int $startedAtNs;

    public function __construct(
        private readonly string $traceId,
        private readonly ?string $parentTraceId,
        private readonly Configuration $configuration,
        private readonly WriterInterface $writer,
        private readonly PayloadSanitizer $sanitizer,
        private readonly ClockInterface $clock,
        private readonly Metrics $metrics,
    ) {
        $this->startedAtNs = $clock->monotonicNs();
    }

    public function id(): string
    {
        return $this->traceId;
    }

    public function parentId(): ?string
    {
        return $this->parentTraceId;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws TracingException When $name is empty. That is a programming error,
     *         so it surfaces regardless of failOnError.
     */
    public function event(string $name, array $data = []): void
    {
        $name = $this->normalizeName($name);

        // Only I/O and payload handling are fail-safe. Everything above this point
        // is a caller mistake and must surface.
        try {
            $now = $this->clock->now();
            $elapsedMs = ($this->clock->monotonicNs() - $this->startedAtNs) / 1_000_000;

            $event = new TraceEvent(
                timestamp: $now,
                traceId: $this->traceId,
                parentTraceId: $this->parentTraceId,
                name: $name,
                sequence: $this->sequence + 1,
                elapsedMs: $elapsedMs,
                data: $this->sanitizer->sanitize($data),
            );

            $this->writer->write($event);
        } catch (\Throwable $exception) {
            $this->metrics->recordDroppedEvent();
            $this->handleFailure($exception);

            return;
        }

        // Advanced only after a successful write, so a gap in `sequence` always means
        // a lost event rather than a consumed number.
        $this->sequence++;
    }

    public function flush(): void
    {
        try {
            $this->writer->flush();
        } catch (\Throwable $exception) {
            $this->handleFailure($exception);
        }
    }

    /**
     * An empty name is API misuse and throws. Everything else is coerced rather than
     * rejected: names are often built from request data ("webhook_{$type}"), and a
     * fail-safe tracer must not turn a log-injection attempt into an application crash.
     */
    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new TracingException('Trace event name must not be empty.');
        }

        // \p{C} covers control characters, including the ESC that drives ANSI escape
        // sequences. Event names get rendered straight into an operator's terminal.
        $clean = preg_replace('/\p{C}+/u', '', $name);

        if ($clean === null) {
            // Subject was not valid UTF-8; json_encode() would reject the whole record.
            $clean = self::INVALID_NAME;
        }

        $clean = trim($clean);

        if ($clean === '') {
            $clean = self::INVALID_NAME;
        }

        if (strlen($clean) > self::MAX_NAME_LENGTH) {
            $clean = rtrim(Utf8::truncate($clean, self::MAX_NAME_LENGTH));
        }

        return $clean;
    }

    private function handleFailure(\Throwable $exception): void
    {
        if ($this->configuration->failOnError) {
            throw $exception;
        }

        if ($this->configuration->onError === null) {
            return;
        }

        try {
            ($this->configuration->onError)($exception);
        } catch (\Throwable) {
        }
    }
}
