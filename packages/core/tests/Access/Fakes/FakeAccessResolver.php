<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access\Fakes;

use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Testkit\Sessions\TransactionalSession;
use LogicException;
use Override;

/**
 * The access resolver in memory, as a session of a fake transaction: a test gives an actor its
 * compiled regions and the classification its roles allow, and resolve() gives the actor's
 * AccessContext, with the classification capped by the credential's ceiling as the compiler caps
 * it; an actor without any gets no regions and public access. An actor on behalf of others gets the
 * intersection of its context and theirs, through the AccessCompiler as the Postgres resolver. The context it set is current() until
 * the transaction ends, and there is none outside one, where resolve() throws TransactionRequired.
 * AccessResolverBehaviour holds it to PostgresAccessResolver.
 */
final class FakeAccessResolver implements AccessResolver, TransactionalSession
{
    /** @var array<string, array{list<AccessRegion>, ClassificationAccess}> by actor id */
    private array $grants = [];

    private bool $open = false;

    private ?AccessContext $current = null;

    /** @var list<AccessContext> every context it set, in order */
    public private(set) array $resolved = [];

    /**
     * @param  list<AccessRegion>  $regions
     */
    public function grant(ActorId $actor, array $regions, ClassificationAccess $classification): self
    {
        $this->grants[$actor->toString()] = [$regions, $classification];

        return $this;
    }

    #[Override]
    public function resolve(Principal $principal, ?ClassificationAccess $ceiling = null): AccessContext
    {
        if (! $this->open) {
            throw TransactionRequired::forAccessContext();
        }

        if (! $principal instanceof ActorPrincipal) {
            $context = AccessContext::anonymous();
        } else {
            $context = $this->own($principal, $principal->actor);

            if ($principal->onBehalfOf !== []) {
                $context = new AccessCompiler()->intersect($principal, $context, ...array_map(
                    fn (ActorId $delegator): AccessContext => $this->own($principal, $delegator),
                    $principal->onBehalfOf,
                ));
            }
        }

        if ($ceiling instanceof ClassificationAccess) {
            $context = new AccessContext($context->principal, $context->regions, $context->classificationAccess->atMost($ceiling));
        }

        $this->resolved[] = $context;

        return $this->current = $context;
    }

    /**
     * The context the actor's own grants give the principal.
     */
    private function own(ActorPrincipal $principal, ActorId $actor): AccessContext
    {
        [$regions, $classification] = $this->grants[$actor->toString()] ?? [[], ClassificationAccess::Public];

        return new AccessContext($principal, $regions, $classification->atMost($principal->classificationCeiling()));
    }

    /**
     * The context in effect, or null when none is set.
     */
    public function current(): ?AccessContext
    {
        return $this->current;
    }

    #[Override]
    public function begin(): void
    {
        if ($this->open) {
            throw new LogicException('The fake access resolver already has a transaction open.');
        }

        $this->open = true;
    }

    #[Override]
    public function commit(): void
    {
        $this->end();
    }

    #[Override]
    public function rollBack(): void
    {
        $this->end();
    }

    #[Override]
    public function inTransaction(): bool
    {
        return $this->open;
    }

    private function end(): void
    {
        $this->open = false;
        $this->current = null;
    }
}
