<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RefusedChange;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeType;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use PHPUnit\Framework\Assert;

/*
 * What hooks see of a plan and how their changes enter it, beside the pipeline's action tests:
 * sub-plans keep their place, a later change of a field wins, a revision of an unknown type shows
 * no field, and a variant written twice cannot be changed.
 */

const PLANS_OTHER_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000000e7';

function plansAccess(ClassificationAccess $access = ClassificationAccess::Internal): AccessContext
{
    return new AccessContext(new ActorPrincipal(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000b1'), [], IssuerKind::Service, ClassificationAccess::Sensitive), [], $access);
}

function plansRevision(string $entry, FieldValues $fields, ?TypeId $type = null): RevisionCreated
{
    return new RevisionCreated(EntryId::fromString($entry), $type ?? TypeId::fromString(ProbeType::ID), VariantKey::shared(), RevisionNumber::first(), $fields);
}

function plansFields(string $label, string ...$extension): FieldValues
{
    return new FieldValues(
        new FieldMap(new NamedValue(new FieldHandle('label'), new TextValue($label))),
        ...array_map(
            static fn (string $namespace): ExtensionFields => new ExtensionFields(new FieldNamespace($namespace), new FieldMap(new NamedValue(new FieldHandle('tag'), new TextValue('t')))),
            $extension,
        ),
    );
}

function plansVariant(string $entry): VariantRef
{
    return new VariantRef(EntryId::fromString($entry), VariantKey::shared());
}

it('changes a revision inside a sub-plan and keeps every step and sub-plan in its place', function (): void {
    $head = new HeadMoved(EntryId::fromString(PipelineWorld::ENTRY), VariantKey::shared(), null, RevisionNumber::first());
    $inner = plansRevision(PLANS_OTHER_ENTRY, plansFields('Inner'));
    $outer = plansRevision(PipelineWorld::ENTRY, plansFields('Outer'));
    $plan = new Plan($outer, new Plan($inner, new Plan), $head);

    $changed = new HookPlans(new FakeTypeCatalog(ProbeType::definition()))->apply($plan, new FieldChanges(
        FieldChange::own(plansVariant(PLANS_OTHER_ENTRY), new FieldHandle('label'), new TextValue('First')),
        FieldChange::own(plansVariant(PLANS_OTHER_ENTRY), new FieldHandle('label'), new TextValue('Second')),
    ), plansAccess());

    Assert::assertInstanceOf(Plan::class, $changed);
    Assert::assertSame($outer, $changed->steps[0]);
    Assert::assertSame($head, $changed->steps[2]);
    Assert::assertInstanceOf(Plan::class, $changed->steps[1]);
    Assert::assertInstanceOf(Plan::class, $changed->steps[1]->steps[1]);

    $revision = $changed->steps[1]->steps[0];
    Assert::assertInstanceOf(RevisionCreated::class, $revision);

    expect($revision->fields->equals(plansFields('Second')))->toBeTrue()
        ->and($revision->entry->equals($inner->entry))->toBeTrue()
        ->and($revision->type->equals($inner->type))->toBeTrue()
        ->and($revision->variant->equals($inner->variant))->toBeTrue()
        ->and($revision->revision)->toEqual($inner->revision)
        ->and($changed->steps[1]->steps[1]->isEmpty())->toBeTrue()
        ->and($inner->fields->equals(plansFields('Inner')))->toBeTrue();
});

it('adds an extension field beside the other extenders\' fields', function (): void {
    $plan = new Plan(plansRevision(PipelineWorld::ENTRY, plansFields('Note', 'zeta')));

    $changed = new HookPlans(new FakeTypeCatalog(ProbeType::definition()))->apply($plan, new FieldChanges(
        FieldChange::extension(plansVariant(PipelineWorld::ENTRY), new FieldNamespace(ProbeType::EXTENDER), new FieldHandle('tag'), new TextValue('t')),
    ), plansAccess());

    Assert::assertInstanceOf(Plan::class, $changed);
    $revision = $changed->mutations()[0];
    Assert::assertInstanceOf(RevisionCreated::class, $revision);

    expect($revision->fields->equals(plansFields('Note', ProbeType::EXTENDER, 'zeta')))->toBeTrue();
});

it('refuses a change of a variant the plan writes two revisions of', function (): void {
    $plan = new Plan(plansRevision(PipelineWorld::ENTRY, plansFields('One')), plansRevision(PipelineWorld::ENTRY, plansFields('Two')));
    $change = FieldChange::own(plansVariant(PipelineWorld::ENTRY), new FieldHandle('label'), new TextValue('Three'));

    $refused = new HookPlans(new FakeTypeCatalog(ProbeType::definition()))->apply($plan, new FieldChanges($change), plansAccess());

    Assert::assertInstanceOf(RefusedChange::class, $refused);

    expect($refused->change)->toBe($change)
        ->and($refused->reason)->toBe('The plan writes 2 revisions of the variant "variant:'.PipelineWorld::ENTRY.':shared", so a change of it is ambiguous.');
});

it('stops at the first change it refuses and applies none of the rest', function (): void {
    $plan = new Plan(plansRevision(PipelineWorld::ENTRY, plansFields('One')));
    $bad = FieldChange::own(plansVariant(PipelineWorld::ENTRY), new FieldHandle('slug'), new TextValue('x'));

    $refused = new HookPlans(new FakeTypeCatalog(ProbeType::definition()))->apply($plan, new FieldChanges(
        FieldChange::own(plansVariant(PipelineWorld::ENTRY), new FieldHandle('label'), new TextValue('Two')),
        $bad,
        FieldChange::own(plansVariant(PipelineWorld::ENTRY), new FieldHandle('label'), new TextValue('Three')),
    ), plansAccess());

    Assert::assertInstanceOf(RefusedChange::class, $refused);

    expect($refused->change)->toBe($bad);
});

it('returns the plan itself when there is nothing to change', function (): void {
    $plan = new Plan(plansRevision(PipelineWorld::ENTRY, plansFields('One')));

    expect(new HookPlans(new FakeTypeCatalog(ProbeType::definition()))->apply($plan, FieldChanges::none(), plansAccess()))->toBe($plan);
});

it('shows no field of a revision whose type the catalog does not know, and no empty extension', function (): void {
    $unknown = plansRevision(PipelineWorld::ENTRY, plansFields('Hidden', ProbeType::EXTENDER), TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000d9'));
    $known = plansRevision(PLANS_OTHER_ENTRY, new FieldValues(new FieldMap, new ExtensionFields(new FieldNamespace(ProbeType::EXTENDER), new FieldMap(new NamedValue(new FieldHandle('code'), new TextValue('secret'))))));

    $view = new HookPlans(new FakeTypeCatalog(ProbeType::definition()))->view(new CommandName('probe.rename'), 1, plansAccess(), new Plan($unknown, $known));

    expect($view->revisions()[0]->fields->equals(new FieldValues))->toBeTrue()
        ->and($view->revisions()[1]->fields->equals(new FieldValues))->toBeTrue()
        ->and($view->revisions()[1]->fields->extensions)->toBe([])
        ->and($view->revisions()[0]->entry->equals($unknown->entry))->toBeTrue()
        ->and($view->revisions()[0]->type->equals($unknown->type))->toBeTrue()
        ->and($view->revisions()[0]->revision)->toEqual($unknown->revision);
});

it('shows every field to an actor whose access reaches the highest classification', function (): void {
    $fields = new FieldValues(
        new FieldMap(new NamedValue(new FieldHandle('label'), new TextValue('L')), new NamedValue(new FieldHandle('memo'), new TextValue('M'))),
        new ExtensionFields(new FieldNamespace(ProbeType::EXTENDER), new FieldMap(new NamedValue(new FieldHandle('code'), new TextValue('C')))),
    );

    $view = new HookPlans(new FakeTypeCatalog(ProbeType::definition()))->view(new CommandName('probe.rename'), 1, plansAccess(ClassificationAccess::Sensitive), new Plan(plansRevision(PipelineWorld::ENTRY, $fields)));

    expect($view->revisions()[0]->fields->equals($fields))->toBeTrue()
        ->and($view->classificationAccess)->toBe(ClassificationAccess::Sensitive);
});

it('shows only public fields to the anonymous principal', function (): void {
    $fields = new FieldValues(
        new FieldMap(new NamedValue(new FieldHandle('label'), new TextValue('L')), new NamedValue(new FieldHandle('memo'), new TextValue('M'))),
    );

    $view = new HookPlans(new FakeTypeCatalog(ProbeType::definition()))->view(new CommandName('probe.rename'), 1, AccessContext::anonymous(), new Plan(plansRevision(PipelineWorld::ENTRY, $fields)));

    expect($view->revisions()[0]->fields->equals(plansFields('L')))->toBeTrue();
});
