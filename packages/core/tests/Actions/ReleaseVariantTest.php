<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Entries\Actions\ReleaseVariantAction;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\BoundHook;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use Cbox\Cms\Core\Tests\Entries\NoteType;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackValidate;
use ReflectionAttribute;
use ReflectionClass;

/*
 * variant.release's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.6, 6.2, 6.4): a
 * release reads the entry and the head of its shared variant and plans VariantReleased of the
 * revision it names, for the entry's own type. The kernel validates the revision against its own
 * schema version at the release stage (invariant 5), and rejects a revision the variant does not
 * have, a type that has no revision to release with type_not_releasable, and an agent, whose
 * credential or envelope says so, as agent_visibility_forbidden (invariant 18). It is version_conflict when the
 * variant is not at the version the caller saw, when the entry does not exist, and when the
 * variant changed before the commit.
 */

/**
 * A world with the entry at version 2, the head of its shared variant at version 6 on draft
 * revision 4, nothing released, and revision 4 stored under NoteType's schema version 3 with the
 * fields given, a valid note unless a test gives others.
 */
function releaseVariantWorld(?FieldValues $fields = null, ?StoredHead $head = null, int $schemaVersion = 3): EntryActionWorld
{
    $world = new EntryActionWorld;
    $world->entries
        ->withEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(2))
        ->withHead(EntryActionWorld::entry(), VariantKey::shared(), $head ?? new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), null));
    $world->revisions->with(EntryActionWorld::entry(), VariantKey::shared(), new RevisionNumber(4), $schemaVersion, $fields ?? EntryActionWorld::fields('Groceries'));

    return $world;
}

/**
 * A world whose entry is of a test type with the capabilities given, which the catalog knows
 * beside NoteType.
 */
function releaseVariantWorldOfType(History $history, Stages $stages): EntryActionWorld
{
    $type = new TypeDefinition(
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000003d2'),
        new TypeName('test:reading'),
        1,
        new TypeCapabilities($history, $stages, Localization::None, false),
        [],
        [],
    );
    $world = new EntryActionWorld;
    $world->types = [$type];
    $world->entries
        ->withEntry(EntryActionWorld::entry(), $type->id, EntryActionWorld::home(), new AggregateVersion(1))
        ->withHead(EntryActionWorld::entry(), VariantKey::shared(), new StoredHead(new AggregateVersion(3), new RevisionNumber(3), new RevisionNumber(3), null));
    $world->revisions->with(EntryActionWorld::entry(), VariantKey::shared(), new RevisionNumber(3), 1, new FieldValues);

    return $world;
}

/**
 * @return list<string>
 */
function releaseVariantCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

it('plans the release of the revision it names, for the entry\'s type, and reads the entry and the variant at their versions', function (): void {
    $world = releaseVariantWorld();

    $result = $world->release(6, 4);
    $pending = $world->committed();
    $variant = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());
    [$release] = $pending->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('variant.release')
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $pending->plan->mutations()))->toBe([VariantReleased::class])
        ->and($release instanceof VariantReleased ? [$release->entry->toString(), $release->type->toString(), $release->variant->value, $release->revision->value] : [])
        ->toBe([EntryActionWorld::ENTRY, NoteType::ID, 'shared', 4])
        ->and($pending->reads->of(EntryActionWorld::entry()))->toEqual(ReadVersion::at(EntryActionWorld::entry(), new AggregateVersion(2)))
        ->and($pending->reads->of($variant))->toEqual(ReadVersion::at($variant, new AggregateVersion(6)));
});

it('releases an older revision than the draft, and a revision again when another is released', function (): void {
    $world = releaseVariantWorld(head: new StoredHead(new AggregateVersion(8), new RevisionNumber(6), new RevisionNumber(7), new RevisionNumber(7)));

    $result = $world->release(8, 4);
    [$release] = $world->committed()->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($release instanceof VariantReleased ? $release->revision->value : null)->toBe(4);
});

it('commits nothing when the revision is the released one already', function (): void {
    $world = releaseVariantWorld(head: new StoredHead(new AggregateVersion(7), new RevisionNumber(4), new RevisionNumber(4), new RevisionNumber(4)));

    $result = $world->release(7, 4);

    expect(releaseVariantCodes($result))->toBe(['validation_failed'])
        ->and($result->errors[0]->message)->toContain('changes nothing')
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a release by an agent\'s credential, and by an envelope that records an agent, as agent_visibility_forbidden (invariant 18)', function (): void {
    $byCredential = releaseVariantWorld();
    $byCredential->credential = IssuerKind::Agent;
    $byEnvelope = releaseVariantWorld();
    $byEnvelope->issuer = EnvelopeIssuer::Agent;

    $credential = $byCredential->release(6, 4);
    $envelope = $byEnvelope->release(6, 4);

    expect(releaseVariantCodes($credential))->toBe(['agent_visibility_forbidden'])
        ->and($credential->errors[0]->message)->toContain('invariant 18')
        ->and($credential->errors[0]->message)->toContain('variant:'.EntryActionWorld::ENTRY.':shared')
        ->and(releaseVariantCodes($envelope))->toBe(['agent_visibility_forbidden'])
        ->and($byCredential->committer->pending)->toBe([])
        ->and($byEnvelope->committer->pending)->toBe([]);
});

it('rejects a revision that does not validate at the release stage, with the errors below the revision', function (): void {
    $world = releaseVariantWorld(EntryActionWorld::fields('Groceries', ['colour' => new TextValue('green')]));
    $untitled = releaseVariantWorld(new FieldValues);

    $invalid = $world->release(6, 4);
    $missing = $untitled->release(6, 4);

    expect(releaseVariantCodes($invalid))->toBe(['validation_failed', 'validation_unknown_field'])
        ->and($invalid->errors[1]->path?->toString())->toBe('revision.colour')
        ->and(releaseVariantCodes($missing))->toBe(['validation_failed', 'validation_required'])
        ->and($missing->errors[1]->path?->toString())->toBe('revision.title')
        ->and($world->committer->pending)->toBe([])
        ->and($untitled->committer->pending)->toBe([]);
});

it('rejects a revision the variant does not have, and one written under another schema version than the type\'s', function (): void {
    $unknown = releaseVariantWorld()->release(6, 9);
    $older = releaseVariantWorld(schemaVersion: 2)->release(6, 4);

    expect(releaseVariantCodes($unknown))->toBe(['validation_failed', 'validation_failed'])
        ->and($unknown->errors[1]->message)->toContain('has no revision 9')
        ->and($unknown->errors[1]->path?->toString())->toBe('revision')
        ->and(releaseVariantCodes($older))->toBe(['validation_failed', 'validation_failed'])
        ->and($older->errors[1]->message)->toContain('written under schema version 2 of test:note');
});

it('gives the hooks the released revision with the fields they may read, and adds a validate hook\'s error to the release', function (): void {
    $world = releaseVariantWorld(EntryActionWorld::fields('Groceries', ['secret' => new TextValue('hidden')]));
    $seen = [];
    $world->hooks->add(new CommandName('variant.release'), 1, new BoundHook(
        new CallbackValidate(static function (PlanView $plan) use (&$seen): HookErrors {
            $seen[] = $plan;

            return new HookErrors(HookError::onField(new FieldHandle('title'), 'Released notes need a longer title.'));
        }),
        'acme/cms-titles',
        Phase::Validate,
        0,
        5,
    ));

    $result = $world->release(6, 4);
    $view = $seen[0] ?? null;

    expect($seen)->toHaveCount(1)
        ->and($view?->releases())->toHaveCount(1)
        ->and($view?->release(new VariantRef(EntryActionWorld::entry(), VariantKey::shared()))?->release->revision->value)->toBe(4)
        ->and($view?->releases()[0]->fields->equals(EntryActionWorld::fields('Groceries')))->toBeTrue()
        ->and(releaseVariantCodes($result))->toBe(['validation_failed', 'validation_hook_failed'])
        ->and($result->errors[1]->message)->toBe('Released notes need a longer title.')
        ->and($world->committer->pending)->toBe([]);
});

it('gives the hooks no released revision the kernel cannot read, and rejects the release after them', function (): void {
    $world = releaseVariantWorld(schemaVersion: 2);
    $seen = [];
    $world->hooks->add(new CommandName('variant.release'), 1, new BoundHook(
        new CallbackValidate(static function (PlanView $plan) use (&$seen): HookErrors {
            $seen[] = $plan->releases();

            return HookErrors::none();
        }),
        'acme/cms-titles',
        Phase::Validate,
        0,
        5,
    ));

    $result = $world->release(6, 4);

    expect($seen)->toBe([[]])
        ->and(releaseVariantCodes($result))->toBe(['validation_failed', 'validation_failed']);
});

it('rejects a release of a type with stages none, and of one whose history keeps no revisions, with type_not_releasable', function (): void {
    $unstaged = releaseVariantWorldOfType(History::None, Stages::None);
    $unrecorded = releaseVariantWorldOfType(History::AuditOnly, Stages::DraftRelease);

    $none = $unstaged->release(3, 3);
    $auditOnly = $unrecorded->release(3, 3);

    expect(releaseVariantCodes($none))->toBe(['type_not_releasable'])
        ->and($none->errors[0]->message)->toContain('test:reading has stages none')
        ->and(releaseVariantCodes($auditOnly))->toBe(['type_not_releasable'])
        ->and($auditOnly->errors[0]->message)->toContain('keeps no revision')
        ->and($unstaged->committer->pending)->toBe([])
        ->and($unrecorded->committer->pending)->toBe([]);
});

it('is version_conflict when the variant is not at the version the caller saw, and for an entry that does not exist', function (): void {
    $world = releaseVariantWorld();

    $stale = $world->release(5, 4);
    $absent = new EntryActionWorld()->release(1, 1);

    expect(releaseVariantCodes($stale))->toBe(['version_conflict'])
        ->and($stale->errors[0]->message)->toContain('expected version 5, found version 6')
        ->and(releaseVariantCodes($absent))->toBe(['version_conflict'])
        ->and($absent->errors[0]->message)->toContain('expected version 1, found no aggregate')
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when another call moved the variant before the commit', function (): void {
    $variant = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());
    $world = releaseVariantWorld()->commitWith(new VersionConflict(new StaleRead($variant, new AggregateVersion(6), new AggregateVersion(7))));

    $result = $world->release(6, 4);

    expect(releaseVariantCodes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('changed after it was read: expected version 6, found version 7');
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = releaseVariantWorld();
    $world->release(6, 4);
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(ReleaseVariantAction::class)->getAttributes(Action::class),
    );

    expect($world->committed()->command->value)->toBe('variant.release')
        ->and($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
