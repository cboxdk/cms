<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use Cbox\Cms\Core\Seeding\Domain\EntryGenerator;
use Cbox\Cms\Core\Seeding\Domain\SeedProfiles;
use Cbox\Cms\Core\Seeding\Domain\SeedTypes;
use InvalidArgumentException;

/*
 * The entries of a seeded data set (GUARDRAILS 4.3): entry n depends only on the profile, the seed,
 * n, the types and the nodes, its id rises with n, and the type mix and the entries per node are
 * skewed by the profile.
 */

/**
 * @return list<NodeId>
 */
function seedNodes(int $count): array
{
    return array_map(static fn (int $node): NodeId => NodeId::fromString(sprintf('0192a0c0-0000-7000-8000-%012x', 0x4900 + $node)), range(0, $count - 1));
}

function entryGenerator(int $seed = 3, int $nodes = 10): EntryGenerator
{
    $catalog = SeedTypes::of(app(TypeCatalog::class)->all(), app(TypeValidators::class), ClassificationAccess::Internal);

    return new EntryGenerator(SeedProfiles::small(), $seed, $catalog->types, seedNodes($nodes), ClassificationAccess::Internal);
}

it('gives entry n the same id, type, home, fields and release in every run, whatever else it gave', function (): void {
    $one = entryGenerator()->entry(1234);
    $generator = entryGenerator();
    $generator->entry(5);
    $again = $generator->entry(1234);

    expect($again->entry->equals($one->entry))->toBeTrue()
        ->and($again->type->toString())->toBe($one->type->toString())
        ->and($again->home->toString())->toBe($one->home->toString())
        ->and($again->fields->equals($one->fields))->toBeTrue()
        ->and($again->release)->toBe($one->release)
        ->and(entryGenerator(seed: 4)->entry(1234)->entry->equals($one->entry))->toBeFalse();
});

it('gives rising, distinct ids whose time is the anchor plus the index in milliseconds', function (): void {
    $generator = entryGenerator();
    $anchor = Uuid7::unixMillisecondsOf(SeedProfiles::small()->anchor);
    $previous = null;

    foreach ([0, 1, 2, 99, 100, 5000] as $index) {
        $id = $generator->entry($index)->entry->value;

        expect($id->unixMilliseconds())->toBe($anchor + $index);

        if ($previous instanceof Uuid7) {
            expect($id->compareTo($previous))->toBeGreaterThan(0);
        }

        $previous = $id;
    }

    expect(fn (): SeededEntry => $generator->entry(-1))->toThrow(InvalidArgumentException::class);
});

it('skews the type mix and the entries per node, and releases only entries of a releasable type', function (): void {
    $generator = entryGenerator();
    $types = [];
    $homes = [];
    $released = [];

    for ($index = 0; $index < 3000; $index++) {
        $entry = $generator->entry($index);
        $types[$entry->type->toString()] = ($types[$entry->type->toString()] ?? 0) + 1;
        $homes[$entry->home->toString()] = ($homes[$entry->home->toString()] ?? 0) + 1;
        $released[$entry->type->toString()] = ($released[$entry->type->toString()] ?? 0) + ($entry->release ? 1 : 0);
    }

    $catalog = app(TypeCatalog::class);
    $article = $catalog->named(new TypeName('app:fixture_article'))?->id->toString() ?? '';
    $measurement = $catalog->named(new TypeName('app:fixture_measurement'))?->id->toString() ?? '';
    $nodes = array_map(static fn (NodeId $node): string => $node->toString(), seedNodes(10));

    expect($types[$article])->toBeGreaterThan($types[$measurement])
        ->and($homes[$nodes[0]])->toBeGreaterThan($homes[$nodes[9]] * 3)
        ->and($released[$measurement])->toBe(0)
        ->and($released[$article] / $types[$article])->toBeGreaterThan(0.8);
});

it('needs a type, a node and a seed of 0 or more', function (): void {
    $catalog = SeedTypes::of(app(TypeCatalog::class)->all(), app(TypeValidators::class), ClassificationAccess::Internal);

    expect(fn (): EntryGenerator => new EntryGenerator(SeedProfiles::small(), 1, [], seedNodes(1), ClassificationAccess::Internal))->toThrow(InvalidArgumentException::class)
        ->and(fn (): EntryGenerator => new EntryGenerator(SeedProfiles::small(), 1, $catalog->types, [], ClassificationAccess::Internal))->toThrow(InvalidArgumentException::class)
        ->and(fn (): EntryGenerator => new EntryGenerator(SeedProfiles::small(), -1, $catalog->types, seedNodes(1), ClassificationAccess::Internal))->toThrow(InvalidArgumentException::class)
        ->and(entryGenerator()->entry(0))->toBeInstanceOf(SeededEntry::class);
});
