<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Illuminate\Database\Connection;
use LogicException;

/**
 * A connection without a name and without a PDO, with the transaction level a test sets: whatever
 * would reach Postgres throws PdolessConnection::NO_PDO, so a test sees whether a callback refused
 * the call before it got that far.
 */
final class PdolessConnection extends Connection
{
    public const string NO_PDO = 'The connection has no PDO.';

    public function __construct(int $level = 0)
    {
        parent::__construct(static fn (): never => throw new LogicException(self::NO_PDO));
        $this->transactions = $level;
    }
}
