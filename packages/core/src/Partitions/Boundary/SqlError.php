<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Database\QueryException;

/**
 * The SQLSTATE and the server's message of a failed query, read from the PDO error info.
 */
#[Internal]
final readonly class SqlError
{
    /** lock_not_available: a lock wait passed lock_timeout, or NOWAIT found the lock taken. */
    public const string LOCK_NOT_AVAILABLE = '55P03';

    /** check_violation, which Postgres also raises when no partition covers a row. */
    public const string CHECK_VIOLATION = '23514';

    private function __construct(
        public string $sqlState,
        public string $message,
    ) {}

    public static function of(QueryException $exception): self
    {
        $info = $exception->errorInfo;
        $state = is_array($info) && is_string($info[0] ?? null) ? $info[0] : null;
        $message = is_array($info) && is_string($info[2] ?? null) ? $info[2] : null;

        if ($state === null) {
            $code = $exception->getCode();
            $state = is_string($code) ? $code : (string) $code;
        }

        return new self($state, $message ?? $exception->getPrevious()?->getMessage() ?? $exception->getMessage());
    }

    public function is(string $sqlState): bool
    {
        return $this->sqlState === $sqlState;
    }
}
