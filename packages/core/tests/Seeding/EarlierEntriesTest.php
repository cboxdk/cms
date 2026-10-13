<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\EarlierEntries;
use Cbox\Cms\Core\Seeding\Domain\EntryGenerator;
use Cbox\Cms\Core\Seeding\Domain\SeedProfiles;
use Cbox\Cms\Core\Tests\Pipeline\Probe\LinkedProbeType;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeType;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
 * The targets of seeded relations (PRD 5.11): entry n names entries with a lower index, which
 * exist when it is written, of a type its field allows, the same in every run; with no such entry
 * it names itself when its own type is allowed, and otherwise nothing.
 */

function linkedGenerator(): EntryGenerator
{
    return new EntryGenerator(
        SeedProfiles::small(),
        5,
        [new SeedableType(LinkedProbeType::definition(), new LinkedProbeType()->rules(), true), new SeedableType(ProbeType::definition(), new ProbeType()->rules(), true)],
        [NodeId::fromString('0192a0c0-0000-7000-8000-000000004a01')],
        ClassificationAccess::Internal,
    );
}

/**
 * The index of the entry with the id, from 0 to $last, or null.
 */
function linkedIndex(EntryGenerator $generator, string $id, int $last): ?int
{
    return array_find(range(0, $last), static fn (int $candidate): bool => $generator->identity($candidate)->id->toString() === $id);
}

it('names earlier entries of the allowed types, the same in every run', function (): void {
    $generator = linkedGenerator();
    $named = 0;

    foreach (range(1, 60) as $index) {
        $entry = $generator->entry($index);
        $next = $entry->fields->own->get(new FieldHandle('next'));
        $cited = $entry->fields->own->get(new FieldHandle('cited'));
        $targets = [
            ...($next instanceof TextValue ? [[$next, ['test:linked']]] : []),
            ...array_map(static fn (mixed $item): array => [$item, ['test:linked', 'test:probe']], $cited instanceof ListValue ? $cited->items : []),
        ];

        foreach ($targets as [$target, $allowed]) {
            $at = $target instanceof TextValue ? linkedIndex($generator, $target->value, $index) : null;

            expect($at)->not->toBeNull()
                ->and($allowed)->toContain($generator->identity((int) $at)->type->definition->name->value);
            $named++;
        }

        expect($entry)->toEqual(linkedGenerator()->entry($index));
    }

    expect($named)->toBeGreaterThan(0);
});

it('names the entry itself when no earlier entry has an allowed type, and nothing when its own type is not allowed either', function (): void {
    $generator = linkedGenerator();
    $first = $generator->identity(0);
    $random = new Randomizer(new Xoshiro256StarStar(str_repeat('a', 32)));
    $self = new EarlierEntries($generator, $first, 0);
    $own = $first->type->definition->name;
    $other = $own->value === 'test:linked' ? new TypeName('test:probe') : new TypeName('test:linked');

    expect($self->pick([$own], $random))->toEqual($first->id)
        ->and($self->pick([$other], $random))->toBeNull()
        ->and($self->pick([new TypeName('test:elsewhere')], $random))->toBeNull();
});
