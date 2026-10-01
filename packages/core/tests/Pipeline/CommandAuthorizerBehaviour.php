<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Tests\Access\Fixtures\AuthorizedProbe;
use Cbox\Cms\Core\Tests\Access\Fixtures\TargetedAggregates;
use Cbox\Cms\Core\Tests\Access\Fixtures\UntargetedAggregates;
use LogicException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every CommandAuthorizer does (PRD 5.10, 6.2 phase 2), held against the fake the pipeline's
 * tests use, FakeCommandAuthorizer::byGrants(), and the kernel's GrantCommandAuthorizer on
 * Postgres: it allows a command on each target where the actor's permissions hold, refuses it on
 * a target where they do not, also when another target of the same command is allowed, and
 * refuses the anonymous principal and aggregates that name no target.
 */
trait CommandAuthorizerBehaviour
{
    abstract protected function commandAuthorizer(): CommandAuthorizer;

    abstract protected function actor(): ActorPrincipal;

    /**
     * Commands the actor may run on one target each, by what allows them.
     *
     * @return array<string, array{CommandName, AuthorizationTarget}>
     */
    abstract protected function permitted(): array;

    /**
     * Commands the actor may not run on one target each, by why; the first has the command of a
     * permitted case.
     *
     * @return array<string, array{CommandName, AuthorizationTarget}>
     */
    abstract protected function refused(): array;

    /**
     * Begins the transaction the authorizer runs in, with the principal's context set.
     */
    abstract protected function begin(Principal $principal): AccessContext;

    abstract protected function end(): void;

    #[Test]
    public function it_allows_a_command_where_the_actor_s_permission_holds(): void
    {
        foreach ($this->permitted() as $case => [$command, $target]) {
            Assert::assertTrue($this->decided($this->actor(), $command, new TargetedAggregates($target))->allowed(), $case);
        }
    }

    #[Test]
    public function it_refuses_a_command_where_the_actor_s_permission_does_not_hold(): void
    {
        foreach ($this->refused() as $case => [$command, $target]) {
            $decision = $this->decided($this->actor(), $command, new TargetedAggregates($target));

            Assert::assertFalse($decision->allowed(), $case);
            Assert::assertNotSame('', trim((string) $decision->reason), $case);
        }
    }

    #[Test]
    public function it_refuses_a_command_when_one_of_its_targets_is_refused(): void
    {
        [$command, $refused] = $this->firstCase($this->refused());
        $permitted = null;

        foreach ($this->permitted() as [$name, $target]) {
            if ($name->equals($command)) {
                $permitted = $target;

                break;
            }
        }

        Assert::assertInstanceOf(AuthorizationTarget::class, $permitted, 'The first refused case has the command of a permitted one.');
        Assert::assertFalse($this->decided($this->actor(), $command, new TargetedAggregates($permitted, $refused))->allowed());
        Assert::assertTrue($this->decided($this->actor(), $command, new TargetedAggregates($permitted, $permitted))->allowed());
    }

    #[Test]
    public function it_refuses_the_anonymous_principal(): void
    {
        [$command, $target] = $this->firstCase($this->permitted());

        Assert::assertFalse($this->decided(new AnonymousPrincipal, $command, new TargetedAggregates($target))->allowed());
    }

    #[Test]
    public function it_refuses_aggregates_that_name_no_target(): void
    {
        [$command] = $this->firstCase($this->permitted());

        Assert::assertFalse($this->decided($this->actor(), $command, new TargetedAggregates)->allowed());
        Assert::assertFalse($this->decided($this->actor(), $command, new UntargetedAggregates)->allowed());
    }

    /**
     * @param  array<string, array{CommandName, AuthorizationTarget}>  $cases
     * @return array{CommandName, AuthorizationTarget}
     */
    private function firstCase(array $cases): array
    {
        foreach ($cases as $case) {
            return $case;
        }

        throw new LogicException('The behaviour needs at least one case of each kind.');
    }

    private function decided(Principal $principal, CommandName $command, Aggregates $aggregates): Authorization
    {
        $access = $this->begin($principal);

        try {
            return $this->commandAuthorizer()->authorize($access, $command, new AuthorizedProbe, $aggregates);
        } finally {
            $this->end();
        }
    }
}
