<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Cli\Console\MaintainPartitionsCommand;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Adapter\MissingPartitionMapper;
use Cbox\Cms\Core\Partitions\Boundary\CatalogRow;
use Cbox\Cms\Core\Partitions\Boundary\SqlError;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Infrastructure\PartitionCatalog;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * What the Arch suite lets Infrastructure use (GUARDRAILS 2.5 and 2.2, "Hvor ting bor" in
 * CLAUDE.md). tests/Arch/LayersTest.php holds every Infrastructure class to this list with Pest's
 * toOnlyUse(), so the list is the rule: what it leaves out, Infrastructure may not use.
 */

/**
 * Whether the class or namespace is allowed by an entry of the list: the entry itself, or a
 * namespace the entry is a prefix of, as Pest matches a namespace in toOnlyUse().
 *
 * @param  list<string>  $allowed
 */
function allowedBy(array $allowed, string $name): bool
{
    return array_any($allowed, fn (string $entry): bool => $name === $entry || str_starts_with($name, $entry.'\\'));
}

it('lets Infrastructure use the domain, the contracts, Infrastructure and Illuminate\Database', function (string $name): void {
    expect(allowedBy(Codebase::infrastructureMayUse(), $name))->toBeTrue();
})->with([
    'a domain class' => [PartitionedTable::class],
    'a contract' => [Clock::class],
    'an Infrastructure class' => [PartitionCatalog::class],
    'migration support' => [TablePrivileges::class],
    'a connection' => [Connection::class],
    'an Eloquent model' => [Model::class],
]);

it('lets Infrastructure use the Boundary row mappers and error readers that read what Postgres returns', function (string $name): void {
    expect(allowedBy(Codebase::infrastructureMayUse(), $name))->toBeTrue();
})->with([
    'the catalog row mapper' => [CatalogRow::class],
    'the SQL error reader' => [SqlError::class],
    'the testkit connection settings' => [ConnectionSettings::class],
]);

it('keeps actions, surfaces, Adapter classes other than casts and the rest of the framework out of Infrastructure', function (string $name): void {
    expect(allowedBy(Codebase::infrastructureMayUse(), $name))->toBeFalse();
})->with([
    'an action' => [MaintainPartitions::class],
    'a surface' => [MaintainPartitionsCommand::class],
    'an Adapter mapper' => [MissingPartitionMapper::class],
    'an Adapter store' => [PostgresReceiptStore::class],
    'Illuminate\Http' => [Request::class],
    'Illuminate\Support' => [Str::class],
    'a facade' => [DB::class],
    'the framework contracts' => [Container::class],
    'a namespace that only starts like Illuminate\Database' => ['Illuminate\DatabaseTools\Thing'],
]);

it('counts a class as an Adapter cast only when it implements an Eloquent cast interface', function (): void {
    $cast = new class implements CastsAttributes
    {
        public function get(Model $model, string $key, mixed $value, array $attributes): mixed
        {
            return $value;
        }

        public function set(Model $model, string $key, mixed $value, array $attributes): mixed
        {
            return $value;
        }
    };

    expect(Codebase::isCast($cast::class))->toBeTrue()
        ->and(Codebase::isCast(MissingPartitionMapper::class))->toBeFalse()
        ->and(Codebase::isCast('Cbox\Cms\Core\Nothing\Adapter\Missing'))->toBeFalse();
});
