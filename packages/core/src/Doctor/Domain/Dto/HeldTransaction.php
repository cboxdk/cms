<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A session that holds a transaction id or a snapshot, and how long its transaction has run: the
 * time since xact_start, an upper bound on how long it has held either, because Postgres records
 * neither when it assigned the id nor when it took the snapshot.
 */
#[Internal]
final readonly class HeldTransaction
{
    /**
     * @param  int  $pid  the backend's process id
     * @param  string  $role  the session's role
     * @param  int  $milliseconds  how long its transaction has run
     */
    public function __construct(
        public int $pid,
        public string $role,
        public int $milliseconds,
    ) {}
}
