<?php

declare(strict_types=1);

namespace Golovanov;

use Golovanov\Clock\ClockInterface;
use Golovanov\Exception\TracingException;
use Golovanov\Sanitizer\PayloadSanitizer;
use Golovanov\Writer\WriterInterface;

final class Trace
{
    private int $sequence = 0;
    private readonly float $startedAt;

    public function __construct(
        private readonly string $traceId,
        private readonly Configuration $configuration,
        private readonly WriterInterface $writer,
        private readonly PayloadSanitizer $sanitizer,
        private readonly ClockInterface $clock,
    ) {
        $this->startedAt = $clock->microtime();
    }

    public function id(): string
    {
        return $this->traceId;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function event(string $name, array $data = []): void
    {
        try {
            $name = trim($name);

            if ($name === '') {
                throw new TracingException('Trace event name must not be empty.');
            }

            $now = $this->clock->now();
            $elapsedMs = max(0.0, ($this->clock->microtime() - $this->startedAt) * 1000);
            $this->sequence++;

            $this->writer->write([
                'timestamp' => $this->formatTimestamp($now),
                'trace_id' => $this->traceId,
                'event' => $name,
                'sequence' => $this->sequence,
                'elapsed_ms' => round($elapsedMs, 3),
                'data' => $this->sanitizer->sanitize($data),
            ]);
        } catch (\Throwable $exception) {
            $this->handleFailure($exception);
        }
    }

    private function formatTimestamp(\DateTimeImmutable $now): string
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));

        return $utc->format('Y-m-d\TH:i:s.u\Z');
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
