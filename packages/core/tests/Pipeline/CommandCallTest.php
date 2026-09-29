<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Pipeline\Domain\InvalidCommandCall;
use InvalidArgumentException;

/*
 * The pipeline's DTOs hold their invariants: a call's access context is the envelope's principal,
 * a refusal has a reason, a commit answers with a committed receipt, and a conflict names what
 * went stale, sorted by aggregate key.
 */

function callWithPrincipal(PipelineWorld $world, AccessContext $access): CommandCall
{
    $envelope = $world->call($world->command())->envelope;

    return new CommandCall($world->command(), $envelope, $access);
}

it('takes the access context of the envelope\'s actor and chain', function (): void {
    $world = new PipelineWorld;
    $person = $world->identity->addActor(ActorClass::Staff)->id;
    $call = $world->call($world->command(), false, $person);

    expect($call->access->principal)->toBeInstanceOf(ActorPrincipal::class);
});

it('refuses an access context of another principal than the envelope names', function (): void {
    $world = new PipelineWorld;
    $other = $world->identity->addActor(ActorClass::Staff)->id;
    $person = $world->identity->addActor(ActorClass::Staff)->id;
    $message = 'The access context is not the principal of the envelope, the actor '.$world->editor->toString();

    expect(static fn (): CommandCall => callWithPrincipal($world, AccessContext::anonymous()))->toThrow(InvalidCommandCall::class, $message)
        ->and(static fn (): CommandCall => callWithPrincipal($world, new AccessContext(new ActorPrincipal($other, [], IssuerKind::Service, ClassificationAccess::Public), [], ClassificationAccess::Public)))->toThrow(InvalidCommandCall::class, $message)
        ->and(static fn (): CommandCall => callWithPrincipal($world, new AccessContext(new ActorPrincipal($world->editor, [$person], IssuerKind::Service, ClassificationAccess::Public), [], ClassificationAccess::Public)))->toThrow(InvalidCommandCall::class, $message);

    $withChain = $world->call($world->command(), false, $person, $other)->envelope;

    expect(static fn (): CommandCall => new CommandCall($world->command(), $withChain, new AccessContext(new ActorPrincipal($world->editor, [$other, $person], IssuerKind::Service, ClassificationAccess::Public), [], ClassificationAccess::Public)))
        ->toThrow(InvalidCommandCall::class, $message)
        ->and(static fn (): CommandCall => new CommandCall($world->command(), $withChain, new AccessContext(new ActorPrincipal($world->editor, [$person], IssuerKind::Service, ClassificationAccess::Public), [], ClassificationAccess::Public)))
        ->toThrow(InvalidCommandCall::class, $message);
});

it('allows, or refuses with a reason', function (): void {
    expect(Authorization::allow()->allowed())->toBeTrue()
        ->and(Authorization::allow()->reason)->toBeNull()
        ->and(Authorization::refuse('No grant.')->allowed())->toBeFalse()
        ->and(Authorization::refuse('No grant.')->reason)->toBe('No grant.')
        ->and(static fn (): Authorization => Authorization::refuse(' '))->toThrow(InvalidArgumentException::class, 'A refusal gives its reason in plain language.');
});

it('commits only with a committed receipt', function (): void {
    expect(static fn (): Committed => new Committed(Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard)))
        ->toThrow(InvalidArgumentException::class, 'A commit answers with a committed receipt, got rejected.')
        ->and(static fn (): Committed => new Committed(Receipt::dryRun(WaitLevel::Commit, RetentionClass::Standard)))
        ->toThrow(InvalidArgumentException::class, 'got dry_run.');
});

it('names at least one stale read, sorted by aggregate key', function (): void {
    $world = new PipelineWorld;
    $variant = new VariantRef($world->entry(), VariantKey::shared());
    $conflict = new VersionConflict(new StaleRead($variant, null, AggregateVersion::first()), new StaleRead($world->entry(), null, AggregateVersion::first()));

    expect(array_map(static fn (StaleRead $stale): string => $stale->aggregate->aggregateKey(), $conflict->stale))
        ->toBe([$world->entry()->aggregateKey(), $variant->aggregateKey()])
        ->and(static fn (): VersionConflict => new VersionConflict)->toThrow(InvalidArgumentException::class, 'A version conflict names at least one stale read.');
});
