<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Seeding\Boundary\SeedContentHasher;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedEntriesAggregates;
use Cbox\Cms\Core\Seeding\Domain\SeedAuthorizer;
use Cbox\Cms\Core\Seeding\Domain\SeedTypes;
use Cbox\Cms\Core\Tests\Entries\NoteType;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use InvalidArgumentException;

/*
 * What the seeder's pipeline adds to the kernel's (GUARDRAILS 4.3, PRD 5.10, 6.1): which types it
 * can seed, the authorization of seed.entries, and the content hash of a chunk.
 */

function seedEntry(string $id, string $code, ?string $label = null, string $type = LockedType::ID): SeededEntry
{
    $fields = [new NamedValue(new FieldHandle('code'), new TextValue($code))];

    if ($label !== null) {
        $fields[] = new NamedValue(new FieldHandle('label'), new TextValue($label));
    }

    return new SeededEntry(EntryId::fromString($id), TypeId::fromString($type), NodeId::fromString('0192a0c0-0000-7000-8000-0000000047a2'), new FieldValues(new FieldMap(...$fields)), false);
}

function seedAccess(ClassificationAccess $access): AccessContext
{
    return new AccessContext(new ActorPrincipal(ActorId::fromString('0192a0c0-0000-7000-8000-0000000047c1'), [], IssuerKind::Service, ClassificationAccess::Sensitive), [], $access);
}

it('seeds the types whose required fields the actor may write, sorted by name, and says why it leaves out the others', function (): void {
    $types = [LockedType::definition(), NoteType::definition()];
    $validators = new FakeTypeValidators(new LockedType, new NoteType);
    $public = SeedTypes::of($types, $validators, ClassificationAccess::Public);
    $internal = SeedTypes::of($types, $validators, ClassificationAccess::Internal);
    $unvalidated = SeedTypes::of($types, new FakeTypeValidators(new NoteType), ClassificationAccess::Internal);

    expect(array_map(static fn (SeedableType $type): string => $type->definition->name->value, $public->types))->toBe(['test:note'])
        ->and($public->skipped)->toHaveCount(1)
        ->and($public->skipped[0])->toContain('test:locked requires code')->toContain('public')
        ->and(array_map(static fn (SeedableType $type): string => $type->definition->name->value, $internal->types))->toBe(['test:locked', 'test:note'])
        ->and(array_map(static fn (SeedableType $type): bool => $type->releasable, $internal->types))->toBe([false, true])
        ->and($unvalidated->skipped[0])->toContain('no generated validator');
});

it('allows seed.entries within the actor\'s classification access and refuses the rest', function (): void {
    $authorizer = new SeedAuthorizer(new FakeTypeCatalog(LockedType::definition()));
    $chunk = new SeedEntries(seedEntry('0192a0c0-0000-7000-8000-0000000047e1', 'A-1', 'first'));
    $aggregates = new SeedEntriesAggregates([], []);
    $create = new CreateEntry(EntryId::fromString('0192a0c0-0000-7000-8000-0000000047e1'), TypeId::fromString(LockedType::ID), NodeId::fromString('0192a0c0-0000-7000-8000-0000000047a2'), new FieldValues);

    expect($authorizer->authorize(seedAccess(ClassificationAccess::Internal), new CommandName('seed.entries'), $chunk, $aggregates)->allowed())->toBeTrue()
        ->and($authorizer->authorize(seedAccess(ClassificationAccess::Public), new CommandName('seed.entries'), $chunk, $aggregates)->reason)
        ->toContain('writes the field "code" of test:locked, classified internal')
        ->and($authorizer->authorize(seedAccess(ClassificationAccess::Internal), new CommandName('entry.create'), $create, $aggregates)->reason)
        ->toBe('The seeder runs only seed.entries, not entry.create.');
});

it('hashes a chunk by its canonical content, the same for equal chunks in any process', function (): void {
    $hasher = new SeedContentHasher;
    $name = new CommandName('seed.entries');
    $one = new SeedEntries(seedEntry('0192a0c0-0000-7000-8000-0000000047e1', 'A-1', 'first'));
    $same = new SeedEntries(seedEntry('0192a0c0-0000-7000-8000-0000000047e1', 'A-1', 'first'));
    $other = new SeedEntries(seedEntry('0192a0c0-0000-7000-8000-0000000047e1', 'A-2', 'first'));

    expect($hasher->hash($name, 1, $one)->equals($hasher->hash($name, 1, $same)))->toBeTrue()
        ->and($hasher->hash($name, 1, $one)->equals($hasher->hash($name, 1, $other)))->toBeFalse()
        ->and($hasher->hash($name, 1, $one)->equals($hasher->hash($name, 2, $one)))->toBeFalse()
        ->and(SeedContentHasher::canonical($one))->toBe('[{"entry":"0192a0c0-0000-7000-8000-0000000047e1","fields":{"code":"A-1","label":"first"},"home":"0192a0c0-0000-7000-8000-0000000047a2","release":false,"type":"'.LockedType::ID.'"}]')
        ->and(fn (): ContentHash => $hasher->hash(new CommandName('entry.create'), 1, new CreateEntry(EntryId::fromString('0192a0c0-0000-7000-8000-0000000047e1'), TypeId::fromString(LockedType::ID), NodeId::fromString('0192a0c0-0000-7000-8000-0000000047a2'), new FieldValues)))
        ->toThrow(InvalidArgumentException::class, 'only seed.entries');
});

it('holds at least one entry and each entry once', function (): void {
    expect(fn (): SeedEntries => new SeedEntries)->toThrow(InvalidArgumentException::class, 'at least one entry')
        ->and(fn (): SeedEntries => new SeedEntries(seedEntry('0192a0c0-0000-7000-8000-0000000047e1', 'A'), seedEntry('0192a0c0-0000-7000-8000-0000000047e1', 'B')))
        ->toThrow(InvalidArgumentException::class, 'given twice');
});

it('authorizes seed.entries on every home node that was read, in the order read, and anywhere when none was', function (): void {
    $first = NodeId::fromString('0192a0c0-0000-7000-8000-00000000c0a1');
    $second = NodeId::fromString('0192a0c0-0000-7000-8000-00000000c0a2');
    $absent = NodeId::fromString('0192a0c0-0000-7000-8000-00000000c0a3');
    $read = new SeedEntriesAggregates([], [$first->toString() => new AggregateVersion(2), $absent->toString() => null, $second->toString() => new AggregateVersion(1)]);

    expect($read->authorizationScope())->toEqual(AuthorizationScope::on(new AuthorizationTarget($first), new AuthorizationTarget($second)))
        ->and($read->authorizationScope()->isAnywhere())->toBeFalse()
        ->and(new SeedEntriesAggregates([], [$absent->toString() => null])->authorizationScope()->isAnywhere())->toBeTrue()
        ->and(new SeedEntriesAggregates([], [])->authorizationScope()->isAnywhere())->toBeTrue();
});
