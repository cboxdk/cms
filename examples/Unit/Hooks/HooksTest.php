<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Examples\Unit\Hooks\DeriveSlug;
use Examples\Unit\Hooks\NoAgentPublishing;
use Examples\Unit\Hooks\NoShoutedTitles;
use Examples\Unit\Hooks\PublishStory;

// A hook is a plain class: give it the view the kernel would give it and check its answer. The
// view below is what an actor with internal access sees of a plan that writes one revision.

function storyView(string $title, IssuerKind $issuer = IssuerKind::Service): PlanView
{
    return new PlanView(
        new CommandName('story.publish'),
        1,
        new ActorPrincipal(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000b1'), [], $issuer, ClassificationAccess::Confidential),
        ClassificationAccess::Internal,
        new RevisionCreated(
            EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000e1'),
            TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000d1'),
            VariantKey::shared(),
            RevisionNumber::first(),
            new FieldValues(new FieldMap(new NamedValue(new FieldHandle('title'), new TextValue($title)))),
        ),
    );
}

it('denies publishing to an agent and has no objection otherwise', function (): void {
    $hook = new NoAgentPublishing;

    expect($hook->authorize(storyView('A quiet week', IssuerKind::Agent))->reason)->toBe('Stories are published by a person, not by an agent.')
        ->and($hook->authorize(storyView('A quiet week'))->denies())->toBeFalse();
});

it('derives the slug from the title', function (): void {
    $changes = new DeriveSlug()->transform(storyView('A quiet week, mostly'))->changes;

    expect($changes)->toHaveCount(1)
        ->and($changes[0])->toEqual(FieldChange::own(
            new VariantRef(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000e1'), VariantKey::shared()),
            new FieldHandle('slug'),
            new TextValue('a-quiet-week-mostly'),
        ));
});

it('adds an error for a title in capitals only', function (): void {
    $errors = new NoShoutedTitles()->validate(storyView('BREAKING NEWS'))->errors;

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->handle?->value)->toBe('title')
        ->and($errors[0]->message)->toBe('Write the title in sentence case, not in capitals.')
        ->and(new NoShoutedTitles()->validate(storyView('Breaking news'))->isEmpty())->toBeTrue();
});

it('declares each hook for its command, phase, priority and budget', function (string $class, Phase $phase, int $priority, int $budgetMs): void {
    /** @var class-string $class */
    $hook = new ReflectionClass($class)->getAttributes(Hook::class)[0]->newInstance();

    expect($hook->command)->toBe(PublishStory::class)
        ->and($hook->phase)->toBe($phase)
        ->and($hook->priority)->toBe($priority)
        ->and($hook->budgetMs)->toBe($budgetMs)
        ->and(is_a($class, $phase->hookInterface(), true))->toBeTrue();
})->with([
    [NoAgentPublishing::class, Phase::Authorize, 0, 1],
    [DeriveSlug::class, Phase::Transform, 10, 2],
    [NoShoutedTitles::class, Phase::Validate, 0, 1],
]);
