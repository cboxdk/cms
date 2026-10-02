<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements\Fakes;

use Illuminate\Database\Connection;
use LogicException;
use Override;

/**
 * A connection that answers every selectOne() with one row and records each call: the query, the
 * bindings and whether it asked for the read connection.
 */
final class SelectOneConnection extends Connection
{
    /** @var list<array{string, list<mixed>, bool}> */
    public array $calls = [];

    public function __construct(private readonly ?object $row)
    {
        parent::__construct(static fn (): never => throw new LogicException('The connection has no PDO.'));
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     * @param  bool  $useReadPdo
     */
    #[Override]
    public function selectOne($query, $bindings = [], $useReadPdo = true): ?object
    {
        $this->calls[] = [$query, array_values($bindings), $useReadPdo];

        return $this->row;
    }
}
