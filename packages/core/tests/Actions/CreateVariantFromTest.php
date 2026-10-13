<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Mutations\VariantDerived;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Entries\Actions\CreateVariantFromAction;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateVariantFrom;
use Cbox\Cms\Core\Entries\Domain\Dto\CreateVariantFromAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredVariants;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\LocalizedProbeType;
use ReflectionAttribute;
use ReflectionClass;

/*
 * variant.create_from's action in the command pipeline with fakes (GUARDRAILS 9, PRD 9.2, 10.3): it
 * reads the entry with the heads of the new variant and of the source and the source revision, and
 * plans the new variant's first revision as a copy of the source revision's fields, its head on it,
 * and the derivation translated_from, authorized in the new variant's locale. A new variant the
 * entry has, or a source at another version, is version_conflict; a type without languages, the
 * shared variant on either side, a revision the source does not have and one of another schema
 * version are validation_failed.
 */

/**
 * A world with the entry of the localized probe type at version 2, the head of its da variant at
 * version 6 on draft revision 4, and revision 4 stored with a title, unless a test gives another
 * schema version.
 */
function createFromWorld(int $schemaVersion = 1): EntryActionWorld
{
    $world = new EntryActionWorld;
    $world->types = [LocalizedProbeType::definition()];
    $world->validators = [new LocalizedProbeType];
    $da = VariantKey::of(new Locale('da'));
    $world->entries
        ->withEntry(EntryActionWorld::entry(), TypeId::fromString(LocalizedProbeType::ID), EntryActionWorld::home(), new AggregateVersion(2))
        ->withHead(EntryActionWorld::entry(), VariantKey::shared(), new StoredHead(new AggregateVersion(3), new RevisionNumber(1), new RevisionNumber(1), null))
        ->withHead(EntryActionWorld::entry(), $da, new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), null));
    $world->revisions->with(EntryActionWorld::entry(), $da, new RevisionNumber(4), $schemaVersion, EntryActionWorld::fields('Havnen'));

    return $world;
}

/**
 * @return list<string>
 */
function createFromErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value.' '.$error->path?->toString(), $result->errors);
}

it('plans the new variant\'s first revision as a copy of the source revision, its head and the derivation translated_from', function (): void {
    $world = createFromWorld();
    $de = VariantKey::of(new Locale('de'));
    $da = VariantKey::of(new Locale('da'));

    $result = $world->createFrom('de', 'da', 4, 6);
    $pending = $world->committed();
    $target = new VariantRef(EntryActionWorld::entry(), $de);
    $source = new VariantRef(EntryActionWorld::entry(), $da);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('variant.create_from')
        ->and($pending->plan->mutations())->toEqual([
            new RevisionCreated(EntryActionWorld::entry(), TypeId::fromString(LocalizedProbeType::ID), $de, RevisionNumber::first(), EntryActionWorld::fields('Havnen')),
            new HeadMoved(EntryActionWorld::entry(), $de, null, RevisionNumber::first()),
            new VariantDerived(EntryActionWorld::entry(), $de, RevisionNumber::first(), $da, new RevisionNumber(4)),
        ])
        ->and($pending->reads->of($target))->toEqual(ReadVersion::absent($target))
        ->and($pending->reads->of($source))->toEqual(ReadVersion::at($source, new AggregateVersion(6)))
        ->and($pending->reads->of(EntryActionWorld::entry()))->toEqual(ReadVersion::at(EntryActionWorld::entry(), new AggregateVersion(2)));
});

it('is version_conflict for a variant the entry has and for a source at another version', function (): void {
    expect(createFromErrors(createFromWorld()->createFrom('shared', 'da', 4, 6)))->toBe(['version_conflict '])
        ->and(createFromErrors(createFromWorld()->createFrom('de', 'da', 4, 5)))->toBe(['version_conflict '])
        ->and(createFromErrors(createFromWorld()->createFrom('de', 'en', 1, 1)))->toBe(['version_conflict ']);
});

it('refuses the shared variant as the source, a variant from itself, a revision the source does not have and one of another schema version', function (): void {
    $world = createFromWorld();

    expect(createFromErrors($world->createFrom('de', 'shared', 1, 3)))->toBe(['validation_failed source'])
        ->and(createFromErrors($world->createFrom('da', 'da', 4, 6)))->toBe(['validation_failed variant'])
        ->and(createFromErrors($world->createFrom('de', 'da', 3, 6)))->toBe(['validation_failed revision'])
        ->and(createFromErrors(createFromWorld(schemaVersion: 7)->createFrom('de', 'da', 4, 6)))->toBe(['validation_failed revision'])
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a type with localization none', function (): void {
    $world = new EntryActionWorld;
    $da = VariantKey::of(new Locale('da'));
    $world->entries
        ->withEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(1))
        ->withHead(EntryActionWorld::entry(), $da, new StoredHead(new AggregateVersion(1), new RevisionNumber(1), new RevisionNumber(1), null));
    $world->revisions->with(EntryActionWorld::entry(), $da, new RevisionNumber(1), 1, EntryActionWorld::fields('Note'));

    expect(createFromErrors($world->createFrom('de', 'da', 1, 1)))->toBe(['validation_failed variant']);
});

it('validates the copy against the fields the new variant holds', function (): void {
    $world = createFromWorld();
    $world->revisions->with(EntryActionWorld::entry(), VariantKey::of(new Locale('da')), new RevisionNumber(4), 1, new FieldValues);

    expect(createFromErrors($world->createFrom('de', 'da', 4, 6)))->toBe(['validation_failed ', 'validation_required fields.title']);
});

it('is authorized in the new variant\'s locale on the entry\'s home, and is exposed on every surface', function (): void {
    $stored = new StoredVariants(EntryActionWorld::entry(), TypeId::fromString(LocalizedProbeType::ID), EntryActionWorld::home(), new AggregateVersion(2), []);
    $de = new VariantRef(EntryActionWorld::entry(), VariantKey::of(new Locale('de')));
    $da = new VariantRef(EntryActionWorld::entry(), VariantKey::of(new Locale('da')));
    $attributes = new ReflectionClass(CreateVariantFromAction::class)->getAttributes(Action::class);
    $action = array_map(static fn (ReflectionAttribute $attribute): Action => $attribute->newInstance(), $attributes)[0];
    $command = new CreateVariantFrom(EntryActionWorld::entry(), VariantKey::of(new Locale('de')), VariantKey::of(new Locale('da')), new RevisionNumber(4), new AggregateVersion(6));

    expect(new CreateVariantFromAggregates(EntryActionWorld::entry(), $de, $da, $stored)->authorizationScope()->targets)->toEqual([new AuthorizationTarget(EntryActionWorld::home(), new Locale('de'))])
        ->and(new CreateVariantFromAggregates(EntryActionWorld::entry(), $de, $da, null)->authorizationScope()->isAnywhere())->toBeTrue()
        ->and($action->handles)->toBe(CreateVariantFrom::class)
        ->and($action->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])
        ->and($command->expectedVersions()->of($de))->toEqual(ReadVersion::absent($de))
        ->and($command->expectedVersions()->of($da))->toEqual(ReadVersion::at($da, new AggregateVersion(6)));
});
