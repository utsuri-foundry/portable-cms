<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Media;

/** Verified delivery bytes behind a bounded streaming effect; services do not depend on HTTP response types. */
interface MediaDelivery
{
    public function size(): int;

    /** @param resource $output */
    public function writeTo(mixed $output, int $start, int $length): void;
}
