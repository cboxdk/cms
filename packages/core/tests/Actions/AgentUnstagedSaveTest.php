<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Tests\Entries\BriefType;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;

/*
 * An agent never changes what the public reads (invariant 18; security review S-4), with
 * entry.create and entry.revise in the command pipeline with fakes (GUARDRAILS 9). A type with
 * stages none has no draft: a save writes the released row, which the public reads wherever a
 * placement of the entry is visible now or later. So an agent's save of such an entry is
 * agent_visibility_forbidden while a placement shows it, by the agent's credential or by an
 * envelope that records an agent, and commits nothing; a person may save it. An agent may save an
 * entry no placement shows, and its placements then join the reads, so a window a person opens
 * on one before the save commits makes the save version_conflict. The type is test:brief with
 * audit-only history and stages none, and test:brief with full history and stages draft-release.
 */

const UNSTAGED_SHOWN = '01936f5e-8a2b-7c3d-9e4f-0000000004f1';

const UNSTAGED_HIDDEN = '01936f5e-8a2b-7c3d-9e4f-0000000004f2';

/**
 * A world with test:brief of the history in its catalog and an entry of it at version 2 whose
 * shared variant is at version 6 on revision 4, and the caller: an agent, by its credential and its
 * envelope, by its credential alone or by its envelope alone, or a person.
 *
 * @param  string  $caller  'agent', 'agent credential', 'agent envelope' or 'person'
 */
function unstagedWorld(string $caller, History $history = History::AuditOnly): EntryActionWorld
{
    $world = new EntryActionWorld;
    $world->types = [BriefType::definition($history)];
    $world->validators = [new BriefType];
    $world->access = ClassificationAccess::Internal;
    $world->entries
        ->withEntry(EntryActionWorld::entry(), BriefType::definition()->id, EntryActionWorld::home(), new AggregateVersion(2))
        ->withHead(EntryActionWorld::entry(), VariantKey::shared(), new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), null));
    $head = $history === History::Full ? $world->revisions->with(...) : $world->revisions->snapshot(...);
    $head(EntryActionWorld::entry(), VariantKey::shared(), new RevisionNumber(4), 1, unstagedFields('Stored title'));

    if (in_array($caller, ['agent', 'agent credential'], true)) {
        $world->credential = IssuerKind::Agent;
    }

    if (in_array($caller, ['agent', 'agent envelope'], true)) {
        $world->issuer = EnvelopeIssuer::Agent;
    }

    return $world;
}

function unstagedFields(string $title): FieldValues
{
    return new FieldValues(new FieldMap(new NamedValue(new FieldHandle('title'), new TextValue($title))));
}

/**
 * @return list<string> each error as "<code> <path>"
 */
function unstagedErrors(WriteResult $result): array
{
    return array_map(
        static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'),
        $result->errors,
    );
}

it('rejects an agent\'s revise of a stages-none entry a placement shows now or later as agent_visibility_forbidden, and commits nothing', function (string $caller): void {
    $world = unstagedWorld($caller);
    $world->placements
        ->with(EntryActionWorld::entry(), PlacementId::fromString(UNSTAGED_HIDDEN), new AggregateVersion(1), false)
        ->with(EntryActionWorld::entry(), PlacementId::fromString(UNSTAGED_SHOWN), new AggregateVersion(3), true);

    $result = $world->revise(6, unstagedFields('An agent\'s title'));

    expect(unstagedErrors($result))->toBe(['agent_visibility_forbidden -'])
        ->and($result->errors[0]->message)->toContain('stages none')
        ->and($result->errors[0]->message)->toContain(UNSTAGED_SHOWN)
        ->and($world->committer->pending)->toBe([]);
})->with([
    'an agent' => ['agent'],
    'an agent\'s credential' => ['agent credential'],
    'an envelope that records an agent' => ['agent envelope'],
]);

it('lets a person revise a stages-none entry a placement shows', function (): void {
    $world = unstagedWorld('person');
    $world->placements->with(EntryActionWorld::entry(), PlacementId::fromString(UNSTAGED_SHOWN), new AggregateVersion(3), true);

    $result = $world->revise(6, unstagedFields('A person\'s title'));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committed()->reads->of(PlacementId::fromString(UNSTAGED_SHOWN)))->toBeNull();
});

it('lets an agent revise a staged entry a placement shows, which saves a draft the public does not read', function (): void {
    $world = unstagedWorld('agent', History::Full);
    $world->placements->with(EntryActionWorld::entry(), PlacementId::fromString(UNSTAGED_SHOWN), new AggregateVersion(3), true);

    expect($world->revise(6, unstagedFields('An agent\'s draft'))->outcome())->toBe(Outcome::Committed);
});

it('lets an agent revise a stages-none entry no placement shows, holding each placement to the version it read', function (): void {
    $world = unstagedWorld('agent');
    $world->placements->with(EntryActionWorld::entry(), PlacementId::fromString(UNSTAGED_HIDDEN), new AggregateVersion(2), false);

    $result = $world->revise(6, unstagedFields('An agent\'s title'));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committed()->reads->of(PlacementId::fromString(UNSTAGED_HIDDEN)))->toEqual(ReadVersion::at(PlacementId::fromString(UNSTAGED_HIDDEN), new AggregateVersion(2)));
});

it('rejects an agent\'s revise as version_conflict when a person opened a window on a placement of the entry before it committed', function (): void {
    $world = unstagedWorld('agent');
    $hidden = PlacementId::fromString(UNSTAGED_HIDDEN);
    $world->placements->with(EntryActionWorld::entry(), $hidden, new AggregateVersion(2), false);
    $world->committer->at($hidden, new AggregateVersion(3));

    $result = $world->revise(6, unstagedFields('An agent\'s title'));

    expect(unstagedErrors($result))->toBe(['version_conflict -']);
});

it('lets an agent create an entry of a stages-none type, which no placement shows yet', function (): void {
    $world = new EntryActionWorld;
    $world->types = [BriefType::definition(History::AuditOnly)];
    $world->validators = [new BriefType];
    $world->credential = IssuerKind::Agent;
    $world->issuer = EnvelopeIssuer::Agent;

    expect($world->create(unstagedFields('A new title'), BriefType::definition()->id)->outcome())->toBe(Outcome::Committed);
});
