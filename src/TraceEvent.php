<?php

declare(strict_types=1);

namespace Golovanov\Traceloom;

/**
 * One row of the JSONL timeline.
 *
 * This class is the public wire contract. Writers receive it typed and decide how to
 * serialize it; nothing downstream has to re-parse a formatted timestamp to recover
 * the values that produced it.
 */
final readonly class TraceEvent
{
    /**
     * @param array<string, mixed> $data Already sanitized.
     */
    public function __construct(
        public \DateTimeImmutable $timestamp,
        public string $traceId,
        public ?string $parentTraceId,
        public string $name,
        public int $sequence,
        public float $elapsedMs,
        public array $data,
    ) {
    }

    public function utcTimestamp(): \DateTimeImmutable
    {
        if ($this->timestamp->getOffset() === 0) {
            return $this->timestamp;
        }

        return $this->timestamp->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * The UTC calendar day this event belongs to; determines the shard file.
     */
    public function utcDate(): string
    {
        return $this->utcTimestamp()->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $record = [
            'timestamp' => $this->utcTimestamp()->format('Y-m-d\TH:i:s.u\Z'),
            'trace_id' => $this->traceId,
        ];

        if ($this->parentTraceId !== null) {
            $record['parent_trace_id'] = $this->parentTraceId;
        }

        $record['event'] = $this->name;
        $record['sequence'] = $this->sequence;
        $record['elapsed_ms'] = round($this->elapsedMs, 3);
        $record['data'] = $this->data;

        return $record;
    }

    /**
     * Minimal record used when the payload cannot be encoded. Losing `data` is
     * acceptable; losing the fact that the event happened is not.
     *
     * @return array<string, mixed>
     */
    public function toDegradedArray(string $reason): array
    {
        $record = $this->toArray();
        $record['data'] = ['_encoding_error' => $reason];

        return $record;
    }
}
