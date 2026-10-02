<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Consistency;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Core\Consistency\Infrastructure\TransactionPosition;
use Cbox\Cms\Core\Tests\Identity\AnsweringConnection;
use Illuminate\Database\ConnectionResolver;
use LogicException;
use Throwable;

/*
 * The position of the caller's transaction apart from Postgres: it is read on the named
 * connection, and an answer that is no xid8 as text is a LogicException that says so and keeps
 * the reason.
 */

it('reads the position of the open transaction on the named connection', function (): void {
    $connection = new AnsweringConnection('48612', 1);

    expect(new TransactionPosition(new ConnectionResolver(['commands' => $connection]), 'commands')->current())->toEqual(new CommitPosition('48612'))
        ->and($connection->calls)->toBe([[TransactionPosition::CURRENT, [], true]]);
});

it('refuses an answer that is no xid8 as text, with the reason', function (mixed $answer): void {
    $refused = null;

    try {
        new TransactionPosition(new ConnectionResolver(['commands' => new AnsweringConnection($answer, 1)]), 'commands')->current();
    } catch (Throwable $thrown) {
        $refused = $thrown;
    }

    $cause = $refused?->getPrevious();

    expect($refused)->toBeInstanceOf(LogicException::class)
        ->and($cause)->toBeInstanceOf(InvalidReceipt::class)
        ->and($refused?->getMessage())->toBe('Postgres gave the transaction no xid8 as text: '.$cause?->getMessage())
        ->and($cause?->getMessage())->not->toBe('')
        ->and($refused?->getCode())->toBe(0);
})->with([
    'a word' => ['xid'],
    'an integer' => [48612],
]);
