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
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Entries\Actions\ReleaseEntryVariantAction;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseEntryVariant;
use Cbox\Cms\Core\Entries\Domain\Dto\ReleaseEntryVariantAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\LocalizedProbeType;
use ReflectionAttribute;
use ReflectionClass;

/*
 * variant.release version 2's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.6,
 * 6.2, 10.3): a release of a language's variant reads the entry with that variant's head and plans
 * VariantReleased of the revision it names for that variant, authorized in the language's locale.
 * The kernel validates the revision at the release stage against the fields the variant holds, and
 * refuses the release to an agent (invariant 18). It is version_conflict when the variant is not at
 * the version the caller saw.
 */

/**
 * A world with the entry of the localized probe type at version 2, the head of its da variant at
 * version 6 on draft revision 4, nothing released, and revision 4 stored with the fields given, a
 * valid title unless a test gives others.
 */
function releaseLanguageWorld(?FieldValues $fields = null): EntryActionWorld
{
    $world = new EntryActionWorld;
    $world->types = [LocalizedProbeType::definition()];
    $world->validators = [new LocalizedProbeType];
    $da = VariantKey::of(new Locale('da'));
    $world->entries
        ->withEntry(EntryActionWorld::entry(), TypeId::fromString(LocalizedProbeType::ID), EntryActionWorld::home(), new AggregateVersion(2))
        ->withHead(EntryActionWorld::entry(), $da, new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), null));
    $world->revisions->with(EntryActionWorld::entry(), $da, new RevisionNumber(4), 1, $fields ?? EntryActionWorld::fields('Havnen'));

    return $world;
}

/**
 * @return list<string>
 */
function releaseLanguageCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

it('plans the release of the revision of the variant it names, and reads the entry and that variant at their versions', function (): void {
    $world = releaseLanguageWorld();
    $da = new VariantRef(EntryActionWorld::entry(), VariantKey::of(new Locale('da')));

    $result = $world->releaseVariant('da', 6, 4);
    $pending = $world->committed();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('variant.release')
        ->and($pending->version)->toBe(2)
        ->and($pending->plan->mutations())->toEqual([new VariantReleased(EntryActionWorld::entry(), TypeId::fromString(LocalizedProbeType::ID), VariantKey::of(new Locale('da')), new RevisionNumber(4))])
        ->and($pending->reads->of(EntryActionWorld::entry()))->toEqual(ReadVersion::at(EntryActionWorld::entry(), new AggregateVersion(2)))
        ->and($pending->reads->of($da))->toEqual(ReadVersion::at($da, new AggregateVersion(6)));
});

it('is authorized in the language\'s locale, and in every locale for the shared variant', function (): void {
    $stored = new StoredEntry(EntryActionWorld::entry(), TypeId::fromString(LocalizedProbeType::ID), EntryActionWorld::home(), new AggregateVersion(2), null);
    $da = new ReleaseEntryVariantAggregates(new VariantRef(EntryActionWorld::entry(), VariantKey::of(new Locale('da'))), $stored);
    $shared = new ReleaseEntryVariantAggregates(new VariantRef(EntryActionWorld::entry(), VariantKey::shared()), $stored);
    $absent = new ReleaseEntryVariantAggregates(new VariantRef(EntryActionWorld::entry(), VariantKey::shared()), null);

    expect($da->authorizationScope()->targets)->toEqual([new AuthorizationTarget(EntryActionWorld::home(), new Locale('da'))])
        ->and($shared->authorizationScope()->targets)->toEqual([new AuthorizationTarget(EntryActionWorld::home())])
        ->and($absent->authorizationScope()->isAnywhere())->toBeTrue();
});

it('refuses the release to an agent, by its credential or its envelope, with agent_visibility_forbidden (invariant 18)', function (): void {
    $byCredential = releaseLanguageWorld();
    $byCredential->credential = IssuerKind::Agent;
    $byEnvelope = releaseLanguageWorld();
    $byEnvelope->issuer = EnvelopeIssuer::Agent;

    expect(releaseLanguageCodes($byCredential->releaseVariant('da', 6, 4)))->toBe(['agent_visibility_forbidden'])
        ->and(releaseLanguageCodes($byEnvelope->releaseVariant('da', 6, 4)))->toBe(['agent_visibility_forbidden'])
        ->and($byCredential->committer->pending)->toBe([])
        ->and($byEnvelope->committer->pending)->toBe([]);
});

it('validates the released revision against the fields the variant holds, and is version_conflict at a stale version', function (): void {
    $invalid = releaseLanguageWorld(new FieldValues);
    $stale = releaseLanguageWorld();

    $result = $invalid->releaseVariant('da', 6, 4);

    expect(releaseLanguageCodes($result))->toBe(['validation_failed', 'validation_required'])
        ->and($result->errors[1]->path?->toString())->toBe('revision.title')
        ->and(releaseLanguageCodes($stale->releaseVariant('da', 5, 4)))->toBe(['version_conflict'])
        ->and(releaseLanguageCodes($stale->releaseVariant('en', 1, 1)))->toBe(['version_conflict']);
});

it('is exposed on every surface as variant.release version 2', function (): void {
    $attributes = new ReflectionClass(ReleaseEntryVariantAction::class)->getAttributes(Action::class);
    $action = array_map(static fn (ReflectionAttribute $attribute): Action => $attribute->newInstance(), $attributes)[0];
    $command = new ReleaseEntryVariant(EntryActionWorld::entry(), VariantKey::of(new Locale('da')), new RevisionNumber(4), new AggregateVersion(6));

    expect($action->handles)->toBe(ReleaseEntryVariant::class)
        ->and($action->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])
        ->and($command->ref())->toEqual(new VariantRef(EntryActionWorld::entry(), VariantKey::of(new Locale('da'))))
        ->and($command->expectedVersions()->of($command->ref()))->toEqual(ReadVersion::at($command->ref(), new AggregateVersion(6)));
});
