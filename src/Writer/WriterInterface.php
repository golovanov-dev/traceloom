<?php

declare(strict_types=1);

namespace Golovanov\Traceloom\Writer;

use Golovanov\Traceloom\TraceEvent;

interface WriterInterface
{
    public function write(TraceEvent $event): void;

    /**
     * Pushes anything buffered to the operating system.
     */
    public function flush(): void;

    /**
     * Releases underlying handles. Implementations must tolerate being called twice
     * and must remain usable (by reopening) if write() is called afterwards.
     */
    public function close(): void;
}
