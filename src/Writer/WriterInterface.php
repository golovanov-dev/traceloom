<?php

declare(strict_types=1);

namespace Golovanov\Writer;

interface WriterInterface
{
    /**
     * @param array<string, mixed> $record
     */
    public function write(array $record): void;
}
