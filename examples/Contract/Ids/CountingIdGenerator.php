<?php

declare(strict_types=1);

namespace Examples\Contract\Ids;

use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\Uuid7;

/**
 * Wraps another generator and counts the ids this process made, for a metric. It passes every id
 * on unchanged, so the ids keep the order the wrapped generator gives them.
 */
final class CountingIdGenerator implements IdGenerator
{
    private int $made = 0;

    public function __construct(private readonly IdGenerator $inner) {}

    public function next(): Uuid7
    {
        $id = $this->inner->next();
        $this->made++;

        return $id;
    }

    public function made(): int
    {
        return $this->made;
    }
}
