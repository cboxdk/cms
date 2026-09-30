<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Infrastructure\PartitionCatalog;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use InvalidArgumentException;

/*
 * A changed source's layer is the innermost layer segment of its namespace, as in "Hvor ting
 * bor", and decides whether its mutations run against the Postgres suite: Adapter and
 * Infrastructure do.
 */

it('finds the layer from the innermost layer segment and runs Adapter and Infrastructure with Postgres', function (string $name, ?string $layer, bool $postgres): void {
    $source = new ChangedSource('packages/core/src/Some/Thing.php', $name);

    expect($source->layer())->toBe($layer)
        ->and($source->needsPostgres())->toBe($postgres);
})->with([
    'an adapter' => [PostgresReceiptStore::class, 'Adapter', true],
    'infrastructure' => [PartitionCatalog::class, 'Infrastructure', true],
    'the testkit\'s raw SQL' => ['Cbox\Cms\Testkit\Postgres\Infrastructure\Catalog', 'Infrastructure', true],
    'a command below Domain' => [ReleaseVariant::class, 'Domain', false],
    'an action' => [MaintainPartitions::class, 'Actions', false],
    'a boundary' => [DoctorConfig::class, 'Boundary', false],
    'the innermost segment decides' => ['Cbox\Cms\Http\Adapter\Boundary\RequestParser', 'Boundary', false],
    'a class named like a layer' => ['Cbox\Cms\Core\Doctor\Adapter', null, false],
    'DomainEvents is not Domain' => ['Cbox\Cms\Core\DomainEvents\Published', null, false],
    'the contracts package' => [PrincipalId::class, null, false],
    'a service provider' => [CoreServiceProvider::class, null, false],
]);

it('finds the layer of a file that declares nothing from its directories below src', function (): void {
    expect(new ChangedSource('packages/core/src/Doctor/Adapter/helpers.php', 'packages/core/src/Doctor/Adapter/helpers.php')->needsPostgres())->toBeTrue()
        ->and(new ChangedSource('packages/core/src/helpers.php', 'packages/core/src/helpers.php')->layer())->toBeNull();
});

it('sorts the changed sources by path and refuses a source listed twice', function (): void {
    $a = new ChangedSource('packages/a/src/A.php', 'A');
    $b = new ChangedSource('packages/b/src/B.php', 'B');

    expect(MutationScope::changed('base', [$b, $a])->sources)->toBe([$a, $b])
        ->and(static fn (): MutationScope => MutationScope::changed('base', [$a, $a]))->toThrow(InvalidArgumentException::class);
});

it('refuses a path outside packages/<package>/src, one with a comma, and an empty name', function (string $path, string $name): void {
    expect(static fn (): ChangedSource => new ChangedSource($path, $name))->toThrow(InvalidArgumentException::class);
})->with([
    'a test' => ['packages/core/tests/ThingTest.php', 'Thing'],
    'the tooling' => ['tools/src/Check/Domain/Step.php', 'Step'],
    'not PHP' => ['packages/core/src/notes.txt', 'notes'],
    'a comma, which --path splits on' => ['packages/core/src/A,B.php', 'A'],
    'an absolute path' => ['/packages/core/src/A.php', 'A'],
    'a parent directory' => ['packages/core/src/../../tools/A.php', 'A'],
    'no name' => ['packages/core/src/A.php', ''],
]);
