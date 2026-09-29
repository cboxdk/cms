<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Hooks;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\InvalidHookResult;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;

/*
 * The values hooks answer with and the view they receive (GUARDRAILS 2.4, PRD 6.3).
 */

const HOOK_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000000e1';

const HOOK_OTHER_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000000e2';

const HOOK_TYPE = '01936f5e-8a2b-7c3d-9e4f-0000000000d1';

function hookRevision(string $entry, VariantKey $variant): RevisionCreated
{
    return new RevisionCreated(EntryId::fromString($entry), TypeId::fromString(HOOK_TYPE), $variant, RevisionNumber::first(), new FieldValues);
}

it('has no objection, or denies with a reason, and never grants', function (): void {
    expect(HookDecision::noObjection()->denies())->toBeFalse()
        ->and(HookDecision::noObjection()->reason)->toBeNull()
        ->and(HookDecision::deny('Locked.')->denies())->toBeTrue()
        ->and(HookDecision::deny('Locked.')->reason)->toBe('Locked.')
        ->and(get_class_methods(HookDecision::class))->toBe(['noObjection', 'deny', 'denies']);
});

it('refuses a denial without a reason', function (string $reason): void {
    expect(static fn (): HookDecision => HookDecision::deny($reason))
        ->toThrow(InvalidHookResult::class, 'A hook that denies a command gives its reason in plain language.');
})->with(['', "  \n"]);

it('addresses an owner\'s field by handle and an extension field under its namespace', function (): void {
    $variant = new VariantRef(EntryId::fromString(HOOK_ENTRY), VariantKey::shared());
    $own = FieldChange::own($variant, new FieldHandle('slug'), new TextValue('note'));
    $extension = FieldChange::extension($variant, new FieldNamespace('seo'), new FieldHandle('slug'), new TextValue('note'));

    expect($own->address())->toBe('slug')
        ->and($own->namespace)->toBeNull()
        ->and($extension->address())->toBe('ext.seo.slug')
        ->and($extension->namespace?->value)->toBe('seo')
        ->and($extension->variant)->toBe($variant)
        ->and($extension->value)->toEqual(new TextValue('note'));
});

it('keeps the changes and the errors in the order given', function (): void {
    $variant = new VariantRef(EntryId::fromString(HOOK_ENTRY), VariantKey::shared());
    $first = FieldChange::own($variant, new FieldHandle('b'), new TextValue('1'));
    $second = FieldChange::own($variant, new FieldHandle('a'), new TextValue('2'));

    expect(new FieldChanges($first, $second)->changes)->toBe([$first, $second])
        ->and(new FieldChanges($first)->isEmpty())->toBeFalse()
        ->and(FieldChanges::none()->isEmpty())->toBeTrue()
        ->and(FieldChanges::none()->changes)->toBe([]);

    $later = HookError::onCommand('B');
    $earlier = HookError::onField(new FieldHandle('a'), 'A');

    expect(new HookErrors($later, $earlier)->errors)->toBe([$later, $earlier])
        ->and(new HookErrors($later)->isEmpty())->toBeFalse()
        ->and(HookErrors::none()->isEmpty())->toBeTrue()
        ->and(HookErrors::none()->errors)->toBe([]);
});

it('says what a hook error is about', function (): void {
    $field = HookError::onField(new FieldHandle('tag'), 'Retired.', new FieldNamespace('seo'));
    $command = HookError::onCommand('Not today.');

    expect($field->message)->toBe('Retired.')
        ->and($field->handle?->value)->toBe('tag')
        ->and($field->namespace?->value)->toBe('seo')
        ->and(HookError::onField(new FieldHandle('tag'), 'Retired.')->namespace)->toBeNull()
        ->and($command->handle)->toBeNull()
        ->and($command->namespace)->toBeNull();
});

it('refuses a hook error without a message', function (string $message): void {
    expect(static fn (): HookError => HookError::onCommand($message))
        ->toThrow(InvalidHookResult::class, 'A hook error says in plain language what is wrong.');
})->with(['', ' ']);

it('views the mutations in order and finds the revision of a variant', function (): void {
    $created = new EntryCreated(EntryId::fromString(HOOK_ENTRY), TypeId::fromString(HOOK_TYPE), NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000a1'));
    $shared = hookRevision(HOOK_ENTRY, VariantKey::shared());
    $other = hookRevision(HOOK_OTHER_ENTRY, VariantKey::shared());
    $view = new PlanView(new CommandName('note.publish'), 2, new AnonymousPrincipal, ClassificationAccess::Public, $created, $shared, $other);

    expect($view->mutations)->toBe([$created, $shared, $other])
        ->and($view->revisions())->toBe([$shared, $other])
        ->and($view->revision(new VariantRef(EntryId::fromString(HOOK_OTHER_ENTRY), VariantKey::shared())))->toBe($other)
        ->and($view->revision(new VariantRef(EntryId::fromString(HOOK_ENTRY), VariantKey::shared())))->toBe($shared)
        ->and($view->revision(new VariantRef(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000e3'), VariantKey::shared())))->toBeNull()
        ->and($view->command->value)->toBe('note.publish')
        ->and($view->version)->toBe(2)
        ->and($view->classificationAccess)->toBe(ClassificationAccess::Public)
        ->and(new PlanView(new CommandName('note.publish'), 1, new AnonymousPrincipal, ClassificationAccess::Public)->revisions())->toBe([]);
});

it('refuses a view of a command version below 1', function (int $version): void {
    expect(static fn (): PlanView => new PlanView(new CommandName('note.publish'), $version, new AnonymousPrincipal, ClassificationAccess::Public))
        ->toThrow(InvalidHookResult::class, 'A command version starts at 1, got '.$version.'.');
})->with([0, -1]);
