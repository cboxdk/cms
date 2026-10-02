<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Core\Seeding\Boundary\SeedContentHasher;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedRequest;
use Cbox\Cms\Core\Seeding\Domain\EntryGenerator;
use Cbox\Cms\Core\Seeding\Domain\InvalidSeed;
use Cbox\Cms\Core\Seeding\Domain\SeedProfile;
use Cbox\Cms\Core\Seeding\Domain\SeedProfiles;
use Cbox\Cms\Core\Seeding\Domain\SeedRefused;
use Cbox\Cms\Core\Seeding\Domain\SeedRequestLimits;
use Cbox\Cms\Core\Seeding\Domain\SeedTypes;
use InvalidArgumentException;
use ReflectionClass;

/*
 * The exact bounds, values and messages of the seeder (GUARDRAILS 4.3): the two profiles, what a
 * profile, a request and an entry index accept at their edges, the chunk hash, and the messages a
 * refused run ends with.
 */

/**
 * @return list<NodeId>
 */
function boundNodes(int $count): array
{
    return array_map(static fn (int $node): NodeId => NodeId::fromString(sprintf('0192a0c0-0000-7000-8000-%012x', 0x4A00 + $node)), range(0, $count - 1));
}

function boundEntry(string $id, string $code, ?string $label = null): SeededEntry
{
    $fields = [new NamedValue(new FieldHandle('code'), new TextValue($code))];

    if ($label !== null) {
        $fields[] = new NamedValue(new FieldHandle('label'), new TextValue($label));
    }

    return new SeededEntry(EntryId::fromString($id), TypeId::fromString(LockedType::ID), NodeId::fromString('0192a0c0-0000-7000-8000-0000000047a2'), new FieldValues(new FieldMap(...$fields)), false);
}

/**
 * A profile with every setting at the given value or the small profile's.
 */
function profileWith(int $version = 1, int $chunkSize = 10, float $typeSkew = 1.0, float $nodeSkew = 1.0, float $valueSkew = 1.0, int $releasedPercent = 50, int $filledPercent = 50, int $spanDays = 10, float $dateSkew = 2.0): SeedProfile
{
    return new SeedProfile('custom', $version, $chunkSize, $typeSkew, $nodeSkew, $valueSkew, $releasedPercent, $filledPercent, '2026-01-01', $spanDays, $dateSkew);
}

it('has the small and the scale profile with exactly these settings', function (): void {
    foreach ([[SeedProfiles::small(), 'small', 100], [SeedProfiles::scale(), 'scale', 200]] as [$profile, $name, $chunkSize]) {
        expect([
            $profile->name, $profile->version, $profile->chunkSize, $profile->typeSkew, $profile->nodeSkew, $profile->valueSkew,
            $profile->releasedPercent, $profile->filledPercent, $profile->anchor->format('Y-m-d H:i:s e'), $profile->spanDays, $profile->dateSkew,
        ])->toBe([$name, 1, $chunkSize, 1.0, 1.1, 1.0, 90, 80, '2026-01-01 00:00:00 UTC', 3650, 3.0]);
    }
});

it('accepts a profile at the edges of its bounds', function (SeedProfile $profile): void {
    expect($profile->label())->toBe($profile->name.'@'.$profile->version);
})->with([
    'version 1' => [fn (): SeedProfile => profileWith(version: 1)],
    'one entry a chunk' => [fn (): SeedProfile => profileWith(chunkSize: 1)],
    '1000 entries a chunk' => [fn (): SeedProfile => profileWith(chunkSize: 1000)],
    'no skew' => [fn (): SeedProfile => profileWith(typeSkew: 0.0, nodeSkew: 0.0, valueSkew: 0.0)],
    'none released or filled' => [fn (): SeedProfile => profileWith(releasedPercent: 0, filledPercent: 0)],
    'all released and filled' => [fn (): SeedProfile => profileWith(releasedPercent: 100, filledPercent: 100)],
    'a span of a day' => [fn (): SeedProfile => profileWith(spanDays: 1)],
    'a date skew of 1' => [fn (): SeedProfile => profileWith(dateSkew: 1.0)],
    'a name of 32 characters' => [fn (): SeedProfile => new SeedProfile('a'.str_repeat('b', 31), 1, 10, 1.0, 1.0, 1.0, 50, 50, '2026-01-01', 10, 2.0)],
]);

it('refuses a profile just past the edges of its bounds, naming it', function (callable $profile): void {
    expect($profile)->toThrow(InvalidSeed::class, 'The seed profile "custom" version');
})->with([
    'version 0' => [fn (): SeedProfile => profileWith(version: 0)],
    'no entries a chunk' => [fn (): SeedProfile => profileWith(chunkSize: 0)],
    '1001 entries a chunk' => [fn (): SeedProfile => profileWith(chunkSize: 1001)],
    'a negative type skew' => [fn (): SeedProfile => profileWith(typeSkew: -0.01)],
    'a negative node skew' => [fn (): SeedProfile => profileWith(nodeSkew: -0.01)],
    'a negative value skew' => [fn (): SeedProfile => profileWith(valueSkew: -0.01)],
    'released below 0' => [fn (): SeedProfile => profileWith(releasedPercent: -1)],
    'released above 100' => [fn (): SeedProfile => profileWith(releasedPercent: 101)],
    'filled below 0' => [fn (): SeedProfile => profileWith(filledPercent: -1)],
    'filled above 100' => [fn (): SeedProfile => profileWith(filledPercent: 101)],
    'no span' => [fn (): SeedProfile => profileWith(spanDays: 0)],
    'a date skew below 1' => [fn (): SeedProfile => profileWith(dateSkew: 0.99)],
]);

it('refuses a name past 32 characters and an anchor that is no day', function (): void {
    expect(fn (): SeedProfile => new SeedProfile('a'.str_repeat('b', 32), 1, 10, 1.0, 1.0, 1.0, 50, 50, '2026-01-01', 10, 2.0))->toThrow(InvalidSeed::class)
        ->and(fn (): SeedProfile => new SeedProfile('custom', 1, 10, 1.0, 1.0, 1.0, 50, 50, 'soon', 10, 2.0))->toThrow(InvalidSeed::class);
});

it('seeds up to exactly a hundred million entries a run', function (): void {
    expect(SeedRequestLimits::MAX_ENTRIES)->toBe(100_000_000)
        ->and(new SeedRequest(SeedProfiles::small(), 0, 100_000_000)->entries)->toBe(100_000_000)
        ->and(new SeedRequest(SeedProfiles::small(), 0, 1)->chunks())->toBe(1)
        ->and(fn (): SeedRequest => new SeedRequest(SeedProfiles::small(), 0, 100_000_001))->toThrow(InvalidSeed::class, 'A seed run seeds 1 to 100000000 entries, got 100000001.')
        ->and(fn (): SeedRequest => new SeedRequest(SeedProfiles::small(), -1, 1))->toThrow(InvalidSeed::class, 'A seed is a whole number of 0 or more, got -1.');
});

it('generates entries with the indexes 0 to 99999999 and a seed of 0 or more', function (): void {
    $catalog = SeedTypes::of(app(TypeCatalog::class)->all(), app(TypeValidators::class), ClassificationAccess::Internal);
    $generator = new EntryGenerator(SeedProfiles::small(), 0, $catalog->types, boundNodes(1), ClassificationAccess::Internal);

    expect($generator->entry(99_999_999)->entry->toString())->toBeString()
        ->and(fn (): mixed => $generator->entry(100_000_000))->toThrow(InvalidArgumentException::class, 'An entry index is 0 to 99999999, got 100000000.')
        ->and(fn (): mixed => $generator->entry(-1))->toThrow(InvalidArgumentException::class, 'An entry index is 0 to 99999999, got -1.')
        ->and(fn (): EntryGenerator => new EntryGenerator(SeedProfiles::small(), 0, [], boundNodes(1), ClassificationAccess::Internal))->toThrow(InvalidArgumentException::class, 'at least one seedable type')
        ->and(fn (): EntryGenerator => new EntryGenerator(SeedProfiles::small(), 0, $catalog->types, [], ClassificationAccess::Internal))->toThrow(InvalidArgumentException::class, 'at least one node')
        ->and(fn (): EntryGenerator => new EntryGenerator(SeedProfiles::small(), -1, $catalog->types, boundNodes(1), ClassificationAccess::Internal))->toThrow(InvalidArgumentException::class, 'a seed of 0 or more');
});

it('releases the share of a releasable type\'s entries the profile says, none at 0 and all at 100', function (int $percent, callable $check): void {
    $catalog = SeedTypes::of(app(TypeCatalog::class)->all(), app(TypeValidators::class), ClassificationAccess::Internal);
    $releasable = array_values(array_filter($catalog->types, static fn (SeedableType $type): bool => $type->releasable));
    $generator = new EntryGenerator(profileWith(releasedPercent: $percent), 5, $releasable, boundNodes(2), ClassificationAccess::Internal);
    $released = 0;

    for ($index = 0; $index < 1000; $index++) {
        $released += $generator->entry($index)->release ? 1 : 0;
    }

    expect($releasable)->not->toBe([])
        ->and($check($released))->toBeTrue("{$released} of 1000 released at {$percent} %");
})->with([
    'none at 0' => [0, static fn (int $released): bool => $released === 0],
    'about 1 in 100 at 1' => [1, static fn (int $released): bool => $released > 0 && $released < 50],
    'all but about 1 in 100 at 99' => [99, static fn (int $released): bool => $released < 1000 && $released > 950],
    'all at 100' => [100, static fn (int $released): bool => $released === 1000],
]);

it('is seed.entries version 1 and keeps the entries of a chunk as a list, also given by name', function (): void {
    $first = boundEntry('0192a0c0-0000-7000-8000-0000000047e1', 'A');
    $second = boundEntry('0192a0c0-0000-7000-8000-0000000047e2', 'B');

    $attribute = new ReflectionClass(SeedEntries::class)->getAttributes(Command::class)[0]->newInstance();

    expect(new SeedEntries(...['one' => $first, 'two' => $second])->entries)->toBe([$first, $second])
        ->and([$attribute->name, $attribute->version])->toBe(['seed.entries', 1]);
});

it('hashes the command name, the version and the canonical chunk, with slashes and Unicode as they are', function (): void {
    $chunk = new SeedEntries(boundEntry('0192a0c0-0000-7000-8000-0000000047e1', 'A/1', 'fjørd'));
    $canonical = SeedContentHasher::canonical($chunk);

    expect($canonical)->toContain('"code":"A/1"', '"label":"fjørd"')
        ->and(new SeedContentHasher()->hash(new CommandName('seed.entries'), 1, $chunk)->value)
        ->toBe(hash('sha256', "seed.entries\n1\n".$canonical));
});

it('ends a refused run with the message and exit code of its reason', function (callable $refusal, string $message, ExitCode $exit): void {
    $refused = $refusal();

    expect($refused)->toBeInstanceOf(SeedRefused::class);

    if ($refused instanceof SeedRefused) {
        expect($refused->getMessage())->toBe($message)->and($refused->exit)->toBe($exit);
    }
})->with([
    'no types' => [static fn (): SeedRefused => SeedRefused::noTypes([]), 'No type of the catalog can be seeded: the catalog has no types.', ExitCode::Config],
    'types with reasons' => [static fn (): SeedRefused => SeedRefused::noTypes(['one.', 'two.']), 'No type of the catalog can be seeded: one. two.', ExitCode::Config],
    'a rejected chunk' => [
        static fn (): SeedRefused => SeedRefused::rejected('seed:small@1:0:0:5', new CatalogError(ErrorCode::ValidationFailed, null, 'the chunk is invalid'), new CatalogError(ErrorCode::ValidationRequired, new FieldPath('fields', 'code'), 'code is required')),
        'The kernel rejected the seed chunk seed:small@1:0:0:5: validation_failed: the chunk is invalid validation_required at fields.code: code is required',
        ExitCode::Software,
    ],
    'no service actor' => [SeedRefused::notConfigured(...), 'The seeder writes as a service actor, and cbox-cms.seeding.service_actor names none. Create a service actor with a grant on the nodes to seed below and name its id there.', ExitCode::Config],
    'an unknown actor' => [static fn (): SeedRefused => SeedRefused::unknown(ActorId::fromString('0192a0c0-0000-7000-8000-0000000047c1')), 'The service actor 0192a0c0-0000-7000-8000-0000000047c1 that cbox-cms.seeding.service_actor names does not exist.', ExitCode::Config],
    'not a service' => [static fn (): SeedRefused => SeedRefused::notAService(ActorId::fromString('0192a0c0-0000-7000-8000-0000000047c1'), ActorClass::Staff), 'The actor 0192a0c0-0000-7000-8000-0000000047c1 that cbox-cms.seeding.service_actor names is a staff actor; the seeder writes only as a service actor.', ExitCode::Config],
    'not active' => [static fn (): SeedRefused => SeedRefused::notActive(ActorId::fromString('0192a0c0-0000-7000-8000-0000000047c1'), ActorState::Deactivated), 'The service actor 0192a0c0-0000-7000-8000-0000000047c1 is deactivated, not active, so it cannot seed (PRD 5.16).', ExitCode::NoPerm],
]);
