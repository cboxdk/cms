<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Placements\Adapter\PostgresCanonicalPlacementLock;
use Cbox\Cms\Core\Placements\Adapter\TextListColumn;
use Cbox\Cms\Core\Placements\Domain\CanonicalPlacementRef;
use Cbox\Cms\Core\Tests\Placements\Fakes\SelectOneConnection;
use Illuminate\Database\ConnectionResolver;
use InvalidArgumentException;
use JsonException;
use Throwable;
use UnexpectedValueException;

/*
 * Two placement adapters apart from Postgres: the canonical slot's lock reads on the write
 * connection and locks only a canonical slot, and a text[] column read as a JSON array gives its
 * strings and refuses anything else with the column's name.
 */

const ADAPTED_ENTRY = '0192a0c0-0000-7000-8000-00000000f1e1';

/**
 * What $call throws, or null.
 *
 * @param  callable(): mixed  $call
 */
function adapterRefusal(callable $call): ?Throwable
{
    try {
        $call();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    return null;
}

it('reads the canonical slot on the write connection and gives the first version for a held slot, none for a free one', function (): void {
    $held = new SelectOneConnection((object) ['canonical' => true]);
    $free = new SelectOneConnection((object) ['canonical' => false]);
    $slot = new CanonicalPlacementRef(EntryId::fromString(ADAPTED_ENTRY), new Locale('da'));

    expect(new PostgresCanonicalPlacementLock(new ConnectionResolver(['placements' => $held]), 'placements')->lock($slot, LockStrength::Share))->toEqual(AggregateVersion::first())
        ->and(new PostgresCanonicalPlacementLock(new ConnectionResolver(['placements' => $free]), 'placements')->lock($slot, LockStrength::Update))->toBeNull()
        ->and($held->calls)->toBe([[PostgresCanonicalPlacementLock::READ, [ADAPTED_ENTRY, 'da'], false]]);
});

it('locks only the canonical slot of an entry', function (): void {
    $connection = new SelectOneConnection(null);
    $entry = EntryId::fromString(ADAPTED_ENTRY);
    $refused = adapterRefusal(static fn (): ?AggregateVersion => new PostgresCanonicalPlacementLock(new ConnectionResolver(['placements' => $connection]), 'placements')->lock($entry, LockStrength::Share));

    expect($refused)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($refused?->getMessage())->toBe(sprintf('The canonical lock locks the canonical placement of an entry, not "%s".', $entry->aggregateKey()))
        ->and($connection->calls)->toBe([]);
});

it('reads a text[] column given as a JSON array of strings', function (): void {
    expect(TextListColumn::of((object) ['locales' => '["da","en"]'], 'locales'))->toBe(['da', 'en'])
        ->and(TextListColumn::of((object) ['locales' => '[]'], 'locales'))->toBe([]);
});

it('refuses a text[] column that is no JSON array of strings, naming the column', function (string $json, string $message, bool $caused): void {
    $refused = adapterRefusal(static fn (): array => TextListColumn::of((object) ['locales' => $json], 'locales'));

    expect($refused)->toBeInstanceOf(UnexpectedValueException::class)
        ->and($refused?->getMessage())->toBe($message)
        ->and($refused?->getCode())->toBe(0)
        ->and($refused?->getPrevious() instanceof JsonException)->toBe($caused);
})->with([
    'no JSON' => ['[da', 'The column locales is a JSON array.', true],
    'an object' => ['{"da":"en"}', 'The column locales is a JSON array.', false],
    'a number in it' => ['["da",1]', 'The column locales holds strings.', false],
]);
