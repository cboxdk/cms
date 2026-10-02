<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Illuminate\Database\Connection;
use LogicException;
use Override;

/**
 * A connection that answers every select with the same rows, for a probe's reading of what
 * Postgres returns apart from Postgres.
 */
final class RowsConnection extends Connection
{
    /**
     * @param  list<object>  $rows
     */
    public function __construct(private readonly array $rows)
    {
        parent::__construct(static fn (): never => throw new LogicException('The rows connection has no PDO.'));
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     * @param  bool  $useReadPdo
     * @param  array<array-key, mixed>  $fetchUsing
     * @return list<object>
     */
    #[Override]
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->rows;
    }
}
