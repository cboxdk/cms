<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres\QueryProbe;

/**
 * What the probe action saw of the connection in each read, in order: the backend's process id,
 * the context's principal and actor as the policies read them, and the entries it saw.
 */
final class SeenContext
{
    /** @var list<array{pid: int, principal: string, actor: string, entries: list<string>}> */
    public array $reads = [];
}
