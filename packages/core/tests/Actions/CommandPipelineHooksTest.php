<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Adapter\HrtimeStopwatch;
use Cbox\Cms\Core\Pipeline\Domain\OverrunKind;
use Cbox\Cms\Core\Tests\Pipeline\PipelineWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackAuthorize;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackTransform;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackValidate;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\HookLog;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\OtherCallbackTransform;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeType;
use Closure;
use PHPUnit\Framework\Assert;

/*
 * The hooks of the command pipeline (GUARDRAILS 2.4, PRD 6.2, 6.3, invariant 12), with the
 * test-only command probe.rename, fake hooks and the fakes of the pipeline's ports: the order the
 * hooks run in, an authorize hook's denial, a transform hook's changes and the ones the kernel
 * refuses, a validate hook's errors beside the kernel's, the kernel's validation after the
 * transforms, the classification-filtered view, and the budgets on the fake stopwatch and on the
 * real one.
 */

/**
 * @return list<string> each error as "<code> <path>"
 */
function hookErrors(WriteResult $result): array
{
    return array_map(
        static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'),
        $result->errors,
    );
}

function hookVariant(PipelineWorld $world): VariantRef
{
    return new VariantRef($world->entry(), VariantKey::shared());
}

/**
 * The fields of the revision the committed plan writes.
 */
function committedFields(PipelineWorld $world): FieldValues
{
    Assert::assertCount(1, $world->committer->pending);

    return revisionFields($world->committer->pending[0]->plan);
}

function revisionFields(Plan $plan): FieldValues
{
    $revision = array_first(array_filter($plan->mutations(), static fn (object $mutation): bool => $mutation instanceof RevisionCreated)) ?? null;
    Assert::assertInstanceOf(RevisionCreated::class, $revision);

    return $revision->fields;
}

/**
 * The fields of the command: a label, and the given owner's and extender's fields.
 *
 * @param  array<string, FieldValue>  $own
 * @param  array<string, FieldValue>  $extension
 */
function probeFields(string $label, array $own = [], array $extension = []): FieldValues
{
    $named = static function (array $fields): array {
        /** @var array<string, FieldValue> $fields */
        $named = [];

        foreach ($fields as $handle => $value) {
            $named[] = new NamedValue(new FieldHandle($handle), $value);
        }

        return $named;
    };

    return new FieldValues(
        new FieldMap(new NamedValue(new FieldHandle('label'), new TextValue($label)), ...$named($own)),
        ...($extension === [] ? [] : [new ExtensionFields(new FieldNamespace(ProbeType::EXTENDER), new FieldMap(...$named($extension)))]),
    );
}

it('runs the authorize, transform and validate hooks in that order, each phase by priority, then package, then class', function (): void {
    $world = new PipelineWorld;
    $log = new HookLog;
    $authorize = static fn (string $name): CallbackAuthorize => new CallbackAuthorize(static function () use ($log, $name): HookDecision {
        $log->ran($name);

        return HookDecision::noObjection();
    });
    $transform = static fn (string $name, bool $other = false): CallbackTransform|OtherCallbackTransform => $other
        ? new OtherCallbackTransform(static function () use ($log, $name): FieldChanges {
            $log->ran($name);

            return FieldChanges::none();
        })
        : new CallbackTransform(static function () use ($log, $name): FieldChanges {
            $log->ran($name);

            return FieldChanges::none();
        });
    $validate = static fn (string $name): CallbackValidate => new CallbackValidate(static function () use ($log, $name): HookErrors {
        $log->ran($name);

        return HookErrors::none();
    });

    $world->hook($validate('validate 1'), Phase::Validate, 1)
        ->hook($transform('transform 20 acme/a'), Phase::Transform, 20, package: 'acme/a')
        ->hook($transform('transform 10 acme/z'), Phase::Transform, 10, package: 'acme/z')
        ->hook($transform('transform 10 acme/a other class', true), Phase::Transform, 10, package: 'acme/a')
        ->hook($transform('transform 10 acme/a'), Phase::Transform, 10, package: 'acme/a')
        ->hook($transform('transform -5 acme/z'), Phase::Transform, -5, package: 'acme/z')
        ->hook($authorize('authorize 3'), Phase::Authorize, 3)
        ->hook($validate('validate 0'), Phase::Validate, 0)
        ->hook($authorize('authorize 2'), Phase::Authorize, 2);

    $result = $world->run($world->command());

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($log->ran)->toBe([
            'authorize 2',
            'authorize 3',
            'transform -5 acme/z',
            'transform 10 acme/a',
            'transform 10 acme/a other class',
            'transform 10 acme/z',
            'transform 20 acme/a',
            'validate 0',
            'validate 1',
        ]);
});

it('rejects the command as unauthorized with the reason of an authorize hook that denies it, and runs no later hook', function (): void {
    $world = new PipelineWorld;
    $later = new HookLog;
    $world->hook(new CallbackAuthorize(static fn (): HookDecision => HookDecision::deny('The note is locked for review.')), Phase::Authorize, 1)
        ->hook(new CallbackAuthorize(static function () use ($later): HookDecision {
            $later->ran('authorize');

            return HookDecision::noObjection();
        }), Phase::Authorize, 2)
        ->hook(new CallbackTransform(static function () use ($later): FieldChanges {
            $later->ran('transform');

            return FieldChanges::none();
        }), Phase::Transform);

    $result = $world->run($world->command());

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(hookErrors($result))->toBe(['unauthorized -'])
        ->and($result->errors[0]->message)->toBe('The authorize hook '.CallbackAuthorize::class.' of acme/probe denied the command: The note is locked for review.')
        ->and($later->ran)->toBe([])
        ->and($world->committer->pending)->toBe([]);
});

it('commits when every authorize hook has no objection, and runs no hook when the kernel refuses the command', function (): void {
    $world = new PipelineWorld;
    $ran = new HookLog;
    $world->hook(new CallbackAuthorize(static function () use ($ran): HookDecision {
        $ran->ran('authorize');

        return HookDecision::noObjection();
    }), Phase::Authorize);

    expect($world->run($world->command())->outcome())->toBe(Outcome::Committed)
        ->and($ran->ran)->toBe(['authorize']);

    $world->refuse('The editor may not rename in this section.');
    $refused = $world->run($world->command());

    expect(hookErrors($refused))->toBe(['unauthorized -'])
        ->and($refused->errors[0]->message)->toBe('The editor may not rename in this section.')
        ->and($ran->ran)->toBe(['authorize']);
});

it('commits the fields a transform hook changes, and the next hook sees the changed plan', function (): void {
    $world = new PipelineWorld;
    $seen = new HookLog;
    $world->hook(new CallbackTransform(static fn (PlanView $plan): FieldChanges => new FieldChanges(
        FieldChange::own(hookVariant($world), new FieldHandle('rank'), new IntegerValue(7)),
        FieldChange::extension(hookVariant($world), new FieldNamespace(ProbeType::EXTENDER), new FieldHandle('tag'), new TextValue('derived')),
        FieldChange::own(hookVariant($world), new FieldHandle('label'), new TextValue('Trimmed')),
    )), Phase::Transform, 1)
        ->hook(new CallbackTransform(static function (PlanView $plan) use ($seen, $world): FieldChanges {
            $seen->saw($plan);

            return new FieldChanges(FieldChange::own(hookVariant($world), new FieldHandle('rank'), new IntegerValue(8)));
        }), Phase::Transform, 2);

    $result = $world->run($world->command(probeFields('  Trimmed  ', ['rank' => new IntegerValue(1)])));
    $expected = probeFields('Trimmed', ['rank' => new IntegerValue(8)], ['tag' => new TextValue('derived')]);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(committedFields($world)->equals($expected))->toBeTrue()
        ->and($seen->fields(0, hookVariant($world))->equals(probeFields('Trimmed', ['rank' => new IntegerValue(7)], ['tag' => new TextValue('derived')])))->toBeTrue();
});

it('keeps the fields a hook cannot see when it changes others', function (): void {
    $world = new PipelineWorld;
    $world->hook(new CallbackTransform(static fn (): FieldChanges => new FieldChanges(
        FieldChange::extension(hookVariant($world), new FieldNamespace(ProbeType::EXTENDER), new FieldHandle('tag'), new TextValue('derived')),
    )), Phase::Transform);

    $world->run($world->command(probeFields('Note', ['memo' => new TextValue('secret')], ['code' => new TextValue('X-1')])));

    expect(committedFields($world)->equals(probeFields('Note', ['memo' => new TextValue('secret')], ['code' => new TextValue('X-1'), 'tag' => new TextValue('derived')])))->toBeTrue();
});

it('refuses a transform that changes what a hook may not change, and commits nothing', function (Closure $change, string $path, string $reason): void {
    /** @var Closure(PipelineWorld): FieldChange $change */
    $world = new PipelineWorld;
    $world->hook(new CallbackTransform(static fn (): FieldChanges => new FieldChanges($change($world))), Phase::Transform);

    $result = $world->run($world->command());

    expect(hookErrors($result))->toBe(['hook_change_refused '.$path])
        ->and($result->errors[0]->message)->toBe('The transform hook '.CallbackTransform::class.' of acme/probe asked for a change a hook may not make: '.$reason)
        ->and($world->committer->pending)->toBe([]);
})->with([
    'a field the type does not declare' => [
        static fn (PipelineWorld $world): FieldChange => FieldChange::own(hookVariant($world), new FieldHandle('slug'), new TextValue('note')),
        'fields.slug',
        'The type '.ProbeType::ID.' declares no field "slug".',
    ],
    'an extension field the type does not declare' => [
        static fn (PipelineWorld $world): FieldChange => FieldChange::extension(hookVariant($world), new FieldNamespace('other'), new FieldHandle('tag'), new TextValue('note')),
        'fields.ext.other.tag',
        'The type '.ProbeType::ID.' declares no field "ext.other.tag".',
    ],
    'a field above the actor\'s classification access' => [
        static fn (PipelineWorld $world): FieldChange => FieldChange::own(hookVariant($world), new FieldHandle('memo'), new TextValue('changed')),
        'fields.memo',
        'The field "memo" is classified confidential, above the internal classification the actor may read.',
    ],
    'an extension field above the actor\'s classification access' => [
        static fn (PipelineWorld $world): FieldChange => FieldChange::extension(hookVariant($world), new FieldNamespace(ProbeType::EXTENDER), new FieldHandle('code'), new TextValue('changed')),
        'fields.ext.probe.code',
        'The field "ext.probe.code" is classified confidential, above the internal classification the actor may read.',
    ],
    'a variant the plan writes no revision of' => [
        static fn (PipelineWorld $world): FieldChange => FieldChange::own(new VariantRef(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000e9'), VariantKey::shared()), new FieldHandle('label'), new TextValue('elsewhere')),
        'fields.label',
        'The plan writes no revision of the variant "variant:01936f5e-8a2b-7c3d-9e4f-0000000000e9:shared", so a hook cannot change it.',
    ],
]);

it('lets a transform change a confidential field when the actor may read it', function (): void {
    $world = new PipelineWorld;
    $world->access = ClassificationAccess::Confidential;
    $world->hook(new CallbackTransform(static fn (): FieldChanges => new FieldChanges(
        FieldChange::own(hookVariant($world), new FieldHandle('memo'), new TextValue('changed')),
    )), Phase::Transform);

    expect($world->run($world->command())->outcome())->toBe(Outcome::Committed)
        ->and(committedFields($world)->equals(probeFields('Before', ['memo' => new TextValue('changed')])))->toBeTrue();
});

it('validates the plan again after the transforms, so a transform cannot commit fields that break a rule', function (): void {
    $world = new PipelineWorld;
    $world->hook(new CallbackTransform(static fn (): FieldChanges => new FieldChanges(
        FieldChange::own(hookVariant($world), new FieldHandle('label'), new NullValue),
    )), Phase::Transform);

    $result = $world->run($world->command());

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(hookErrors($result))->toBe(['validation_failed -', 'validation_required fields.label'])
        ->and($world->committer->pending)->toBe([]);
});

it('adds a validate hook\'s errors after the kernel\'s, which it cannot remove', function (): void {
    $world = new PipelineWorld;
    $world->hook(new CallbackValidate(static fn (): HookErrors => new HookErrors(
        HookError::onField(new FieldHandle('label'), 'The label repeats the title of another note.'),
        HookError::onField(new FieldHandle('tag'), 'The tag is retired.', new FieldNamespace(ProbeType::EXTENDER)),
        HookError::onCommand('Notes cannot be renamed on Sundays.'),
    )), Phase::Validate);

    $result = $world->run($world->command(new FieldValues));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(hookErrors($result))->toBe([
            'validation_failed -',
            'validation_required fields.label',
            'validation_hook_failed fields.label',
            'validation_hook_failed fields.ext.probe.tag',
            'validation_hook_failed -',
        ])
        ->and($result->errors[0]->message)->toBe('The plan breaks 4 rules of its types; the errors below say which fields to correct.')
        ->and(array_map(static fn (CatalogError $error): string => $error->message, array_slice($result->errors, 2)))->toBe([
            'The label repeats the title of another note.',
            'The tag is retired.',
            'Notes cannot be renamed on Sundays.',
        ])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects the command on a validate hook\'s error alone, and commits when it finds nothing', function (): void {
    $world = new PipelineWorld;
    $answer = new HookLog;
    $world->hook(new CallbackValidate(static fn (): HookErrors => $answer->errors), Phase::Validate);

    expect($world->run($world->command())->outcome())->toBe(Outcome::Committed);

    $answer->errors = new HookErrors(HookError::onCommand('Not today.'));
    $result = $world->run($world->command());

    expect(hookErrors($result))->toBe(['validation_failed -', 'validation_hook_failed -'])
        ->and($result->errors[0]->message)->toBe('The plan breaks 1 rule of its types; the errors below say which fields to correct.')
        ->and($world->committer->pending)->toHaveCount(1);
});

it('gives the validate hooks the plan as the transforms left it', function (): void {
    $world = new PipelineWorld;
    $seen = new HookLog;
    $world->hook(new CallbackTransform(static fn (): FieldChanges => new FieldChanges(
        FieldChange::own(hookVariant($world), new FieldHandle('rank'), new IntegerValue(3)),
    )), Phase::Transform)
        ->hook(new CallbackValidate(static function (PlanView $plan) use ($seen): HookErrors {
            $seen->saw($plan);

            return HookErrors::none();
        }), Phase::Validate);

    $world->run($world->command());

    expect($seen->fields(0, hookVariant($world))->equals(probeFields('Before', ['rank' => new IntegerValue(3)])))->toBeTrue();
});

it('hides a confidential field from the hooks of an actor whose classification access is internal', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $views = new HookLog;
    $world->hook(new CallbackAuthorize(static function (PlanView $plan) use ($views): HookDecision {
        $views->saw($plan);

        return HookDecision::noObjection();
    }), Phase::Authorize);

    $world->run($world->command(probeFields('Note', ['memo' => new TextValue('the confidential memo')], ['code' => new TextValue('X-1'), 'tag' => new TextValue('public')])));
    $world->access = ClassificationAccess::Confidential;
    $world->run($world->command(probeFields('Note', ['memo' => new TextValue('the confidential memo')], ['code' => new TextValue('X-1'), 'tag' => new TextValue('public')])));

    [$internal, $confidential] = [$views->view(0), $views->view(1)];

    expect($internal->classificationAccess)->toBe(ClassificationAccess::Internal)
        ->and($internal->revision(hookVariant($world))?->fields->equals(probeFields('Note', [], ['tag' => new TextValue('public')])))->toBeTrue()
        ->and($confidential->classificationAccess)->toBe(ClassificationAccess::Confidential)
        ->and($confidential->revision(hookVariant($world))?->fields->equals(probeFields('Note', ['memo' => new TextValue('the confidential memo')], ['code' => new TextValue('X-1'), 'tag' => new TextValue('public')])))->toBeTrue()
        ->and($world->committer->pending[0]->plan->mutations()[1])->toBeInstanceOf(RevisionCreated::class)
        ->and(revisionFields($world->committer->pending[0]->plan)->own->get(new FieldHandle('memo')))->toEqual(new TextValue('the confidential memo'));
});

it('gives an addon\'s hook without the capability for an aggregate\'s fields a view without them, and one with it a view with them (invariant 21)', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $world->access = ClassificationAccess::Confidential;
    $publicOnly = new HookLog;
    $internal = new HookLog;
    $confidential = new HookLog;
    $application = new HookLog;
    $logging = static fn (HookLog $log): CallbackAuthorize => new CallbackAuthorize(static function (PlanView $plan) use ($log): HookDecision {
        $log->saw($plan);

        return HookDecision::noObjection();
    });
    $world->hook($logging($publicOnly), Phase::Authorize, 1, package: 'acme/cms-public', reads: ClassificationAccess::Public)
        ->hook($logging($internal), Phase::Authorize, 2, package: 'acme/cms-internal', reads: ClassificationAccess::Internal)
        ->hook($logging($confidential), Phase::Authorize, 3, package: 'acme/cms-confidential', reads: ClassificationAccess::Sensitive)
        ->hook($logging($application), Phase::Authorize, 4);

    $fields = probeFields('Note', ['memo' => new TextValue('the confidential memo')], ['code' => new TextValue('X-1'), 'tag' => new TextValue('public')]);
    $world->run($world->command($fields));

    expect($publicOnly->view(0)->classificationAccess)->toBe(ClassificationAccess::Public)
        ->and($publicOnly->view(0)->revision(hookVariant($world))?->fields->equals(probeFields('Note', [], ['tag' => new TextValue('public')])))->toBeTrue()
        ->and($internal->view(0)->classificationAccess)->toBe(ClassificationAccess::Internal)
        ->and($internal->view(0)->revision(hookVariant($world))?->fields->equals(probeFields('Note', [], ['tag' => new TextValue('public')])))->toBeTrue()
        ->and($confidential->view(0)->classificationAccess)->toBe(ClassificationAccess::Confidential)
        ->and($confidential->view(0)->revision(hookVariant($world))?->fields->equals($fields))->toBeTrue()
        ->and($application->view(0)->classificationAccess)->toBe(ClassificationAccess::Confidential)
        ->and($application->view(0)->revision(hookVariant($world))?->fields->equals($fields))->toBeTrue()
        ->and(committedFields($world)->equals($fields))->toBeTrue();
});

it('never gives an addon\'s hook more than the actor may read, whatever its manifest allows', function (): void {
    $world = new PipelineWorld;
    $views = new HookLog;
    $world->hook(new CallbackValidate(static function (PlanView $plan) use ($views): HookErrors {
        $views->saw($plan);

        return HookErrors::none();
    }), Phase::Validate, package: 'acme/cms-sensitive', reads: ClassificationAccess::Sensitive);

    $world->run($world->command(probeFields('Note', ['memo' => new TextValue('the confidential memo')])));

    expect($views->view(0)->classificationAccess)->toBe(ClassificationAccess::Internal)
        ->and($views->view(0)->revision(hookVariant($world))?->fields->equals(probeFields('Note')))->toBeTrue();
});

it('refuses a transform of an addon\'s hook on a field above what its manifest lets it read, though the actor may read it', function (): void {
    $world = new PipelineWorld;
    $world->access = ClassificationAccess::Confidential;
    $world->hook(new CallbackTransform(static fn (): FieldChanges => new FieldChanges(
        FieldChange::own(hookVariant($world), new FieldHandle('memo'), new TextValue('changed')),
    )), Phase::Transform, package: 'acme/cms-internal', reads: ClassificationAccess::Internal);

    $result = $world->run($world->command());

    expect(hookErrors($result))->toBe(['hook_change_refused fields.memo'])
        ->and($result->errors[0]->message)->toBe('The transform hook '.CallbackTransform::class.' of acme/cms-internal asked for a change a hook may not make: The field "memo" is classified confidential, above the internal classification the actor may read.')
        ->and($world->committer->pending)->toBe([]);
});

it('gives a hook the command, its version, the principal and every mutation of the plan in order', function (): void {
    $world = new PipelineWorld;
    $views = new HookLog;
    $world->hook(new CallbackValidate(static function (PlanView $plan) use ($views): HookErrors {
        $views->saw($plan);

        return HookErrors::none();
    }), Phase::Validate);

    $world->run($world->command());
    $view = $views->view(0);
    $principal = $view->principal;
    Assert::assertInstanceOf(ActorPrincipal::class, $principal);

    expect($view->command->value)->toBe('probe.rename')
        ->and($view->version)->toBe(1)
        ->and($principal->actor->equals($world->editor))->toBeTrue()
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $view->mutations))
        ->toBe(array_map(static fn (Mutation $mutation): string => $mutation::class, $world->committer->pending[0]->plan->mutations()));
});

it('reports the transformed plan in a dry run', function (): void {
    $world = new PipelineWorld;
    $world->hook(new CallbackTransform(static fn (): FieldChanges => new FieldChanges(
        FieldChange::own(hookVariant($world), new FieldHandle('rank'), new IntegerValue(5)),
    )), Phase::Transform);

    $result = $world->run($world->command(), true);

    expect($result->outcome())->toBe(Outcome::DryRun)
        ->and($result->dryRun)->not->toBeNull()
        ->and(revisionFields($result->dryRun->plan ?? Plan::empty())->equals(probeFields('Before', ['rank' => new IntegerValue(5)])))->toBeTrue()
        ->and($world->committer->pending)->toBe([]);
});

it('rejects the command when a hook takes longer than its budget, records the overrun and runs no later hook', function (Phase $phase): void {
    $world = new PipelineWorld;
    $later = new HookLog;
    $slow = match ($phase) {
        Phase::Authorize => new CallbackAuthorize(static function () use ($world): HookDecision {
            $world->stopwatch->advance(5_000_001);

            return HookDecision::noObjection();
        }),
        Phase::Transform => new CallbackTransform(static function () use ($world): FieldChanges {
            $world->stopwatch->advance(5_000_001);

            return FieldChanges::none();
        }),
        Phase::Validate => new CallbackValidate(static function () use ($world): HookErrors {
            $world->stopwatch->advance(5_000_001);

            return HookErrors::none();
        }),
    };
    $world->hook($slow, $phase, 1, 5, 'acme/slow')
        ->hook(new CallbackValidate(static function () use ($later): HookErrors {
            $later->ran('validate');

            return HookErrors::none();
        }), Phase::Validate, 2);

    $result = $world->run($world->command());
    $overrun = $world->overruns->recorded[0] ?? null;

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(hookErrors($result))->toBe(['hook_budget_exceeded -'])
        ->and($result->errors[0]->message)->toBe(sprintf('The %s hook %s of acme/slow took 5.000 ms, over its budget of 5 ms. The command was rejected, and nothing was committed.', $phase->value, $slow::class))
        ->and($world->overruns->recorded)->toHaveCount(1)
        ->and($overrun?->kind)->toBe(OverrunKind::Hook)
        ->and($overrun?->hook)->toBe($slow::class)
        ->and($overrun?->package)->toBe('acme/slow')
        ->and($overrun?->phase)->toBe($phase)
        ->and($overrun?->command->value)->toBe('probe.rename')
        ->and($overrun?->version)->toBe(1)
        ->and($overrun?->budgetMs)->toBe(5)
        ->and($overrun?->elapsedNanoseconds)->toBe(5_000_001)
        ->and($overrun?->spentNanoseconds)->toBe(5_000_001)
        ->and($later->ran)->toBe([])
        ->and($world->committer->pending)->toBe([]);
})->with([Phase::Authorize, Phase::Transform, Phase::Validate]);

it('lets a hook take exactly its budget', function (): void {
    $world = new PipelineWorld;
    $world->hook(new CallbackTransform(static function () use ($world): FieldChanges {
        $world->stopwatch->advanceMilliseconds(20);

        return FieldChanges::none();
    }), Phase::Transform);

    expect($world->run($world->command())->outcome())->toBe(Outcome::Committed)
        ->and($world->overruns->recorded)->toBe([]);
});

it('rejects the command when its hooks together take longer than 100 ms, each within its own budget', function (): void {
    $world = new PipelineWorld;
    $took = static fn (int $nanoseconds): CallbackValidate => new CallbackValidate(static function () use ($world, $nanoseconds): HookErrors {
        $world->stopwatch->advance($nanoseconds);

        return HookErrors::none();
    });
    $world->hook(new CallbackAuthorize(static function () use ($world): HookDecision {
        $world->stopwatch->advanceMilliseconds(20);

        return HookDecision::noObjection();
    }), Phase::Authorize)
        ->hook(new CallbackTransform(static function () use ($world): FieldChanges {
            $world->stopwatch->advanceMilliseconds(20);

            return FieldChanges::none();
        }), Phase::Transform);

    foreach (range(1, 3) as $priority) {
        $world->hook($took(20_000_000), Phase::Validate, $priority);
    }

    expect($world->run($world->command())->outcome())->toBe(Outcome::Committed);

    $world->hook($took(1), Phase::Validate, 4, package: 'acme/last');
    $result = $world->run($world->command());
    $overrun = $world->overruns->recorded[0] ?? null;

    expect(hookErrors($result))->toBe(['hook_budget_exceeded -'])
        ->and($result->errors[0]->message)->toBe('The hooks of probe.rename version 1 took 100.000 ms together, over the budget of 100 ms all hooks of a command have; the last was the validate hook '.CallbackValidate::class.' of acme/last, which took 0.000 ms. The command was rejected, and nothing was committed.')
        ->and($overrun?->kind)->toBe(OverrunKind::Command)
        ->and($overrun?->package)->toBe('acme/last')
        ->and($overrun?->elapsedNanoseconds)->toBe(1)
        ->and($overrun?->spentNanoseconds)->toBe(100_000_001)
        ->and($world->committer->pending)->toHaveCount(1);
});

it('does not charge the time between hooks to them', function (): void {
    $world = new PipelineWorld;
    $world->hook(new CallbackAuthorize(static fn (): HookDecision => HookDecision::noObjection()), Phase::Authorize)
        ->hook(new CallbackValidate(static fn (): HookErrors => HookErrors::none()), Phase::Validate);
    $world->stopwatch->advanceMilliseconds(500);

    expect($world->run($world->command())->outcome())->toBe(Outcome::Committed)
        ->and($world->overruns->recorded)->toBe([]);
});

it('measures the hooks in real time with hrtime', function (): void {
    $world = new PipelineWorld;
    $world->timer = new HrtimeStopwatch;
    $world->hook(new CallbackTransform(static function (): FieldChanges {
        usleep(3_000);

        return FieldChanges::none();
    }), Phase::Transform, budgetMs: 1);

    $result = $world->run($world->command());
    $overrun = $world->overruns->recorded[0] ?? null;

    expect(hookErrors($result))->toBe(['hook_budget_exceeded -'])
        ->and($overrun?->elapsedNanoseconds)->toBeGreaterThanOrEqual(3_000_000)
        ->and($result->errors[0]->code)->toBe(ErrorCode::HookBudgetExceeded);
});
