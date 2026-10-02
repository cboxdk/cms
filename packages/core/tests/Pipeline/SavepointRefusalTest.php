<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Core\Pipeline\Adapter\SavepointRefusal;
use Cbox\Cms\Core\Pipeline\Domain\SavepointRefused;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\PdolessConnection;
use Closure;
use Throwable;

/*
 * The refusal of savepoints apart from Postgres: a connection inside a command refuses a nested
 * transaction and a savepoint statement before either reaches the database, also when it has no
 * name; a command's first transaction, and a connection that has left its command, pass on.
 */

/**
 * What $call throws, or null.
 *
 * @param  Closure(): mixed  $call
 */
function refusalOf(Closure $call): ?Throwable
{
    try {
        $call();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    return null;
}

it('refuses a nested transaction and a savepoint on a connection without a name inside a command', function (): void {
    $connection = new PdolessConnection(1);
    new SavepointRefusal()->enter($connection);

    $nested = refusalOf(static fn () => $connection->beginTransaction());
    $savepoint = refusalOf(static fn (): bool => $connection->statement('SAVEPOINT trans2'));

    expect($nested)->toBeInstanceOf(SavepointRefused::class)
        ->and($savepoint)->toBeInstanceOf(SavepointRefused::class);
});

it('lets the first transaction of a command through to the database', function (): void {
    $connection = new PdolessConnection(0);
    new SavepointRefusal()->enter($connection);

    expect(refusalOf(static fn () => $connection->beginTransaction())?->getMessage())->toBe(PdolessConnection::NO_PDO);
});

it('lets a nested transaction and a savepoint through once the connection has left its command', function (): void {
    $connection = new PdolessConnection(1);
    $refusal = new SavepointRefusal;
    $refusal->enter($connection);
    $refusal->leave($connection);

    expect(refusalOf(static fn () => $connection->beginTransaction()))->not->toBeInstanceOf(SavepointRefused::class)
        ->and(refusalOf(static fn (): bool => $connection->statement('SAVEPOINT trans2')))->not->toBeInstanceOf(SavepointRefused::class);
});
