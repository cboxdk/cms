<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Core\Reads\Adapter\ConnectionQueryTransaction;
use Cbox\Cms\Core\Reads\Domain\QueryTransactionOpen;
use Cbox\Cms\Core\Tests\Identity\AnsweringConnection;
use Illuminate\Database\ConnectionResolver;
use LogicException;
use Throwable;

/*
 * The read transaction apart from Postgres: it refuses to begin inside a transaction open on the
 * named connection and names that connection, and a snapshot whose xmin is no text is a
 * LogicException that says so and keeps the reason.
 */

/**
 * What $call throws, or null.
 *
 * @param  callable(): mixed  $call
 */
function readTransactionRefusal(callable $call): ?Throwable
{
    try {
        $call();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    return null;
}

it('refuses to begin inside an open transaction and names the connection it was given', function (): void {
    $connections = new ConnectionResolver(['reads' => new AnsweringConnection(null, 1)]);
    $connections->setDefaultConnection('other');

    $refused = readTransactionRefusal(static fn (): mixed => new ConnectionQueryTransaction($connections, 'reads')->run(static fn (): never => throw new LogicException('The work never runs.')));

    expect($refused)->toBeInstanceOf(QueryTransactionOpen::class)
        ->and($refused?->getMessage())->toStartWith('The connection "reads" already has a transaction open.');
});

it('refuses a snapshot whose xmin is no text, with the reason', function (): void {
    $refused = readTransactionRefusal(static fn (): mixed => new ConnectionQueryTransaction(new ConnectionResolver(['reads' => new AnsweringConnection(7, 1)]), 'reads')->position());

    expect($refused)->toBeInstanceOf(LogicException::class)
        ->and($refused?->getMessage())->toBe('Postgres gave the snapshot no xmin as text: A commit position is a Postgres xid8 in decimal without leading zeros, 0 to 18446744073709551615, for example "4827", got "".')
        ->and($refused?->getCode())->toBe(0);
});
