<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Pipeline\Domain\InvalidCommandCall;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;
use Cbox\Cms\Core\Tests\Pipeline\PipelineWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeAggregates;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/*
 * The command pipeline (GUARDRAILS 2.1, PRD 6.1, 6.2) called directly with the test-only command
 * probe.rename and the fakes of its ports and of the contracts it reads (GUARDRAILS 9): the
 * identity, the type catalog and the validators. It covers success, the rejections by the resolve,
 * authorize and validate phases, the conflict at commit and the dry run. Each call has an
 * idempotency key of its own; CommandPipelineIdempotencyTest covers the keys.
 */

/**
 * @return list<string> each error as "<code> <path>"
 */
function pipelineErrors(WriteResult $result): array
{
    return array_map(
        static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'),
        $result->errors,
    );
}

/**
 * @return list<string>
 */
function pipelineReads(ReadVersions $reads): array
{
    return array_map(
        static fn (ReadVersion $read): string => $read->aggregate->aggregateKey().'@'.($read->version->value ?? 'absent'),
        $reads->reads,
    );
}

function pipelineVariant(PipelineWorld $world): VariantRef
{
    return new VariantRef($world->entry(), VariantKey::shared());
}

it('commits a new entry with every aggregate it read, the actor included', function (): void {
    $world = new PipelineWorld;
    $call = $world->call($world->command());
    $result = $world->pipeline()->run($call);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($result->receipt->changesetId?->toString())->toBe('01936f5e-8a2b-7c3d-9e4f-0000000000c5')
        ->and($result->errors)->toBe([])
        ->and($world->committer->pending)->toHaveCount(1);

    $pending = $world->committer->pending[0];

    expect($pending->command->value)->toBe('probe.rename')
        ->and($pending->version)->toBe(1)
        ->and(pipelineReads($pending->reads))->toBe([
            'actor:'.$world->editor->toString().'@1',
            'entry:'.PipelineWorld::ENTRY.'@absent',
            'variant:'.PipelineWorld::ENTRY.':shared@absent',
        ])
        ->and(array_map(static fn (Mutation $mutation): string => $mutation::class, $pending->plan->mutations()))->toBe([EntryCreated::class, RevisionCreated::class, HeadMoved::class])
        ->and($pending->access)->toBe($call->access)
        ->and($pending->envelope)->toBe($call->envelope)
        ->and($pending->input)->toBe($call->command)
        ->and($world->authorizer->asked)->toHaveCount(1)
        ->and($world->authorizer->asked[0][1]->value)->toBe('probe.rename')
        ->and($world->validation->asked)->toBe(['test:probe'])
        ->and($world->calls->methods())->toBe(['resolve', 'plan']);
});

it('revises a stored entry at the versions it read', function (): void {
    $world = new PipelineWorld;
    $world->shelf->put($world->entry(), new AggregateVersion(3), new AggregateVersion(5), new RevisionNumber(4));

    $result = $world->run($world->command());

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(pipelineReads($world->committer->pending[0]->reads))->toBe([
            'actor:'.$world->editor->toString().'@1',
            'entry:'.PipelineWorld::ENTRY.'@3',
            'variant:'.PipelineWorld::ENTRY.':shared@5',
        ]);
});

it('gives the committer\'s receipt at the wait level the envelope asks for', function (): void {
    $world = new PipelineWorld;
    $receipt = Receipt::committedWaitTimeout(ChangesetId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000c6'), WaitLevel::Edge, RetentionClass::Evidence);
    $world->commitWith(new Committed($receipt));

    expect($world->run($world->command())->receipt)->toBe($receipt);
});

it('rejects an actor that is not active before it resolves anything', function (ActorState $state): void {
    $world = new PipelineWorld;
    $world->identity->changeState($world->editor, $state);

    $result = $world->run($world->command());

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(pipelineErrors($result))->toBe(['actor_not_active -'])
        ->and($result->errors[0]->message)->toBe(sprintf('The actor, %s, is %s.', $world->editor->toString(), $state->value))
        ->and($result->receipt->changesetId)->toBeNull()
        ->and($world->calls->methods())->toBe([])
        ->and($world->authorizer->asked)->toBe([])
        ->and($world->committer->pending)->toBe([]);
})->with([ActorState::Deactivated, ActorState::Deprovisioned, ActorState::Pending]);

it('rejects a call on behalf of an actor that is not active or does not exist', function (): void {
    $world = new PipelineWorld;
    $person = $world->identity->addActor(ActorClass::Staff, ActorState::Deactivated)->id;
    $missing = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000f9');

    $deactivated = $world->run($world->command(), false, $person);
    $unknown = $world->run($world->command(), false, $missing);

    expect(pipelineErrors($deactivated))->toBe(['actor_not_active -'])
        ->and($deactivated->errors[0]->message)->toBe(sprintf('The actor the call acts on behalf of, %s, is deactivated.', $person->toString()))
        ->and($unknown->errors[0]->message)->toBe(sprintf('The actor the call acts on behalf of, %s, does not exist.', $missing->toString()))
        ->and($world->committer->pending)->toBe([]);
});

it('reads the actor and its on-behalf-of chain as aggregates the commit checks', function (): void {
    $world = new PipelineWorld;
    $person = $world->identity->addActor(ActorClass::Staff)->id;
    $world->identity->changeState($person, ActorState::Deactivated);
    $world->identity->changeState($person, ActorState::Active);

    $world->run($world->command(), false, $person);

    expect(pipelineReads($world->committer->pending[0]->reads))->toContain('actor:'.$person->toString().'@3')
        ->and(pipelineReads($world->committer->pending[0]->reads))->toContain('actor:'.$world->editor->toString().'@1');
});

it('rejects a command the authorizer refuses, with its reason, before it plans', function (): void {
    $world = new PipelineWorld;
    $world->refuse('The actor has no grant on the home node.');

    $result = $world->run($world->command());

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(pipelineErrors($result))->toBe(['unauthorized -'])
        ->and($result->errors[0]->message)->toBe('The actor has no grant on the home node.')
        ->and($world->calls->methods())->toBe(['resolve'])
        ->and($world->validation->asked)->toBe([])
        ->and($world->committer->pending)->toBe([]);
});

it('hands the authorizer the access context, the command and what resolve() read', function (): void {
    $world = new PipelineWorld;
    $command = $world->command();
    $call = $world->call($command);

    $world->pipeline()->run($call);
    [$access, $name, $input, $aggregates] = $world->authorizer->asked[0];

    expect($access)->toBe($call->access)
        ->and($name->value)->toBe('probe.rename')
        ->and($input)->toBe($command)
        ->and($aggregates)->toBe($world->calls->calls[1][1][1]);
});

it('rejects invalid fields with validation_failed and each field error, and commits nothing', function (): void {
    $world = new PipelineWorld;
    $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('colour'), new IntegerValue(3))));

    $result = $world->run($world->command($fields));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(pipelineErrors($result))->toBe(['validation_failed -', 'validation_required fields.label', 'validation_unknown_field fields.colour'])
        ->and($result->errors[0]->message)->toBe('The plan breaks 2 rules of its types; the errors below say which fields to correct.')
        ->and($world->committer->pending)->toBe([]);
});

it('counts one broken rule in the singular', function (): void {
    $world = new PipelineWorld;

    $result = $world->run($world->command(new FieldValues));

    expect($result->errors[0]->message)->toBe('The plan breaks 1 rule of its types; the errors below say which fields to correct.');
});

it('rejects a revision of a type the catalog does not have', function (): void {
    $world = new PipelineWorld;
    $unknown = TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000d9');

    $result = $world->run($world->command(type: $unknown));

    expect(pipelineErrors($result))->toBe(['validation_failed -', 'validation_failed -'])
        ->and($result->errors[1]->message)->toBe('No type of this installation has the id 01936f5e-8a2b-7c3d-9e4f-0000000000d9.')
        ->and($world->validation->asked)->toBe([])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a command whose expected version is not the one resolve() read, before it authorizes', function (): void {
    $world = new PipelineWorld;
    $world->shelf->put($world->entry(), new AggregateVersion(3), new AggregateVersion(5), new RevisionNumber(4));
    $expected = new ReadVersions(ReadVersion::at(pipelineVariant($world), new AggregateVersion(4)));

    $result = $world->run($world->command(expected: $expected));

    expect(pipelineErrors($result))->toBe(['version_conflict -'])
        ->and($result->errors[0]->message)->toBe(sprintf('The aggregate "variant:%s:shared" is not at the version the caller saw: expected version 4, found version 5.', PipelineWorld::ENTRY))
        ->and($world->authorizer->asked)->toBe([])
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a command that expects an aggregate to be absent when it exists, and commits one that sees what is there', function (): void {
    $world = new PipelineWorld;
    $world->shelf->put($world->entry(), new AggregateVersion(1), new AggregateVersion(1), RevisionNumber::first());

    $absent = $world->run($world->command(expected: new ReadVersions(ReadVersion::absent($world->entry()))));
    $current = $world->run($world->command(expected: new ReadVersions(ReadVersion::at($world->entry(), new AggregateVersion(1)))));

    expect($absent->errors[0]->message)->toBe(sprintf('The aggregate "entry:%s" is not at the version the caller saw: expected no aggregate, found version 1.', PipelineWorld::ENTRY))
        ->and($current->outcome())->toBe(Outcome::Committed);
});

it('refuses a command that expects a version of an aggregate its action does not read', function (): void {
    $world = new PipelineWorld;
    $danish = new VariantRef($world->entry(), VariantKey::of(new Locale('da')));
    $expected = new ReadVersions(ReadVersion::at($world->editor, AggregateVersion::first()), ReadVersion::absent($danish));

    expect(fn (): WriteResult => $world->run($world->command(expected: $expected)))
        ->toThrow(InvalidCommandCall::class, 'The command probe.rename expects a version of the aggregate "variant:'.PipelineWorld::ENTRY.':da", which its action\'s resolve() did not read.');
});

it('rejects the call as a version conflict when the commit finds a read changed', function (): void {
    $world = new PipelineWorld;
    $world->commitWith(new VersionConflict(
        new StaleRead(pipelineVariant($world), null, AggregateVersion::first()),
        new StaleRead($world->editor, AggregateVersion::first(), new AggregateVersion(2)),
    ));

    $result = $world->run($world->command());

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(pipelineErrors($result))->toBe(['version_conflict -', 'version_conflict -'])
        ->and($result->errors[0]->message)->toBe(sprintf('The aggregate "actor:%s" changed after it was read: expected version 1, found version 2.', $world->editor->toString()))
        ->and($result->errors[1]->message)->toBe(sprintf('The aggregate "variant:%s:shared" changed after it was read: expected no aggregate, found version 1.', PipelineWorld::ENTRY))
        ->and($result->receipt->changesetId)->toBeNull()
        ->and($world->committer->pending)->toHaveCount(1);
});

it('rejects a call whose action read an aggregate the kernel read at another version', function (): void {
    $world = new PipelineWorld;
    $world->extraReads = [ReadVersion::at($world->editor, new AggregateVersion(2))];

    $conflict = $world->run($world->command());

    $world->extraReads = [ReadVersion::at($world->editor, AggregateVersion::first())];
    $same = $world->run($world->command());

    expect(pipelineErrors($conflict))->toBe(['version_conflict -'])
        ->and($conflict->errors[0]->message)->toBe(sprintf('The aggregate "actor:%s" was read twice in this call at different versions: expected version 1, found version 2.', $world->editor->toString()))
        ->and($same->outcome())->toBe(Outcome::Committed)
        ->and(pipelineReads($world->committer->pending[0]->reads))->toHaveCount(3);
});

it('ends a dry run with the plan, its blast radius and its diff, and commits nothing', function (): void {
    $world = new PipelineWorld;
    $world->shelf->put($world->entry(), new AggregateVersion(3), new AggregateVersion(5), new RevisionNumber(4));

    $result = $world->run($world->command(), true);
    $report = $result->dryRun;

    expect($result->outcome())->toBe(Outcome::DryRun)
        ->and($result->receipt->changesetId)->toBeNull()
        ->and($report)->not->toBeNull()
        ->and($report?->plan->mutations())->toHaveCount(2)
        ->and($report?->blastRadius->mutations)->toBe(2)
        ->and($report?->blastRadius->of('variant'))->toBe(1)
        ->and($report?->blastRadius->of('entry'))->toBe(0)
        ->and($report?->blastRadius->total())->toBe(1)
        ->and(array_map(static fn ($change): string => $change->aggregate->aggregateKey().' '.$change->before?->value.'->'.$change->after->value.' x'.$change->mutations, $report->diff ?? []))
        ->toBe(['variant:'.PipelineWorld::ENTRY.':shared 5->6 x2'])
        ->and($world->authorizer->asked)->toHaveCount(1)
        ->and($world->validation->asked)->toBe(['test:probe'])
        ->and($world->committer->pending)->toBe([]);
});

it('counts a new entry in a dry run as created', function (): void {
    $world = new PipelineWorld;

    $report = $world->run($world->command(), true)->dryRun;

    expect($report?->blastRadius->of('entry'))->toBe(1)
        ->and($report?->blastRadius->of('variant'))->toBe(1)
        ->and($report?->blastRadius->mutations)->toBe(3)
        ->and(array_map(static fn ($change): bool => $change->creates(), $report->diff ?? []))->toBe([true, true]);
});

it('rejects an invalid dry run as it rejects any call', function (): void {
    $world = new PipelineWorld;

    $result = $world->run($world->command(new FieldValues), true);

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and($result->dryRun)->toBeNull();
});

it('refuses a plan that changes an aggregate resolve() did not read', function (): void {
    $world = new PipelineWorld;
    $stranger = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000f8');
    $world->unreadPlan = new Plan(new ActorDeactivated($stranger));

    expect(fn (): WriteResult => $world->run($world->command()))
        ->toThrow(InvalidCommandCall::class, 'changes the aggregate "actor:'.$stranger->toString().'", which its resolve() did not read.');

    expect($world->committer->pending)->toBe([]);
});

it('refuses a command no write action handles', function (): void {
    $world = new PipelineWorld;
    $stray = new readonly class implements Command {};

    expect(fn (): WriteResult => $world->pipeline()->run(new CommandCall($stray, $world->call($world->command())->envelope, $world->call($world->command())->access)))
        ->toThrow(UnknownCommand::class);
});

it('hands resolve() and plan() the command and the aggregates and nothing else', function (): void {
    $world = new PipelineWorld;
    $command = $world->command();

    $world->run($command);
    [[$resolve, $resolveArguments], [$plan, $planArguments]] = $world->calls->calls;

    expect($resolve)->toBe('resolve')
        ->and($resolveArguments)->toBe([$command])
        ->and($plan)->toBe('plan')
        ->and($planArguments)->toHaveCount(2)
        ->and($planArguments[0])->toBe($command)
        ->and($planArguments[1])->toBeInstanceOf(ProbeAggregates::class);

    $parameters = static fn (string $method): array => array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : '?',
        new ReflectionClass(WriteAction::class)->getMethod($method)->getParameters(),
    );

    expect($parameters('resolve'))->toBe([Command::class])
        ->and($parameters('plan'))->toBe([Command::class, Aggregates::class]);
});

it('builds on ports and contracts alone, with no connection to hand an action', function (): void {
    $types = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : '?',
        new ReflectionClass(CommandPipeline::class)->getConstructor()?->getParameters() ?? [],
    );

    expect($types)->each->toStartWith('Cbox\\Cms\\')
        ->and(array_filter($types, static fn (string $type): bool => str_contains($type, 'Illuminate')))->toBe([]);
});

it('refuses an answer of the commit that is none of the two outcomes', function (): void {
    $world = new PipelineWorld;
    $world->commitWith(new readonly class implements CommitOutcome {});

    expect(fn (): WriteResult => $world->run($world->command()))
        ->toThrow(InvalidCommandCall::class, 'which is not one of the two commit outcomes.');
});
