<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity;

use Illuminate\Database\Connection;
use LogicException;
use Override;

/**
 * A connection that answers every scalar query with one answer and records each call: the query,
 * the bindings as text and whether it asked for the read connection.
 */
final class AnsweringConnection extends Connection
{
    /** @var list<array{string, list<string>, bool}> */
    public array $calls = [];

    /**
     * @param  int  $level  the transaction level the connection reports
     */
    public function __construct(private readonly mixed $answer, int $level = 0)
    {
        parent::__construct(static fn (): never => throw new LogicException('The answering connection has no PDO.'));
        $this->transactions = $level;
    }

    /**
     * @param  string  $query
     * @param  array<array-key, scalar|null>  $bindings
     * @param  bool  $useReadPdo
     */
    #[Override]
    public function scalar($query, $bindings = [], $useReadPdo = true): mixed
    {
        $this->calls[] = [$query, array_values(array_map(static fn (string|int|float|bool|null $binding): string => (string) $binding, $bindings)), $useReadPdo];

        return $this->answer;
    }
}
