<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for ActorDirectory (GUARDRAILS 2.3 and 9). The fake and every real
 * directory run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness for a new, empty directory from identity(). The harness's seeder writes what its
 * directory reads:
 *
 *     final class FakeActorDirectoryContractTest extends TestCase
 *     {
 *         use ActorDirectoryContract;
 *
 *         protected function identity(Clock $clock): IdentityHarness
 *         {
 *             return new FakeIdentity($clock);
 *         }
 *     }
 *
 * The cases cover every class and state, an unknown id, and that a change the seeder commits is
 * read at once, with the version and the credential generation counted as PRD 5.16 and 6.4 say.
 */
#[Experimental]
trait ActorDirectoryContract
{
    /**
     * A harness for a new, empty directory whose parts read the time from $clock.
     */
    abstract protected function identity(Clock $clock): IdentityHarness;

    #[Test]
    public function it_reads_a_new_actor_at_version_1_and_generation_1(): void
    {
        $identity = $this->identity(new FakeClock);
        $added = $identity->addActor(ActorClass::Staff);
        $found = $identity->directory()->find($added->id);

        Assert::assertNotNull($found, 'The directory did not find an actor the seeder added.');
        $this->assertActor($added->id, ActorClass::Staff, ActorState::Active, 1, 1, $found);
    }

    #[Test]
    public function it_reads_every_class_and_state_as_it_was_written(): void
    {
        $identity = $this->identity(new FakeClock);

        foreach (ActorClass::cases() as $class) {
            foreach (ActorState::cases() as $state) {
                $added = $identity->addActor($class, $state);

                $this->assertActor($added->id, $class, $state, 1, 1, $identity->directory()->find($added->id));
            }
        }
    }

    #[Test]
    public function it_finds_no_actor_for_an_unknown_id(): void
    {
        $identity = $this->identity(new FakeClock);
        $identity->addActor(ActorClass::Staff);

        Assert::assertNull($identity->directory()->find(new ActorId(new FakeIdGenerator(seed: 99)->next())));
    }

    #[Test]
    public function it_reads_a_deactivation_at_once_with_the_version_and_generation_counted_up(): void
    {
        $identity = $this->identity(new FakeClock);
        $directory = $identity->directory();
        $actor = $identity->addActor(ActorClass::Staff);
        $directory->find($actor->id);

        $identity->changeState($actor->id, ActorState::Deactivated);
        $this->assertActor($actor->id, ActorClass::Staff, ActorState::Deactivated, 2, 2, $directory->find($actor->id));

        $identity->changeState($actor->id, ActorState::Active);
        $this->assertActor($actor->id, ActorClass::Staff, ActorState::Active, 3, 2, $directory->find($actor->id));

        $identity->changeState($actor->id, ActorState::Deprovisioned);
        $this->assertActor($actor->id, ActorClass::Staff, ActorState::Deprovisioned, 4, 3, $directory->find($actor->id));
    }

    #[Test]
    public function it_reads_a_revocation_as_a_new_generation_of_an_active_actor(): void
    {
        $identity = $this->identity(new FakeClock);
        $actor = $identity->addActor(ActorClass::Service);

        $identity->revokeCredentials($actor->id);
        $identity->revokeCredentials($actor->id);

        $this->assertActor($actor->id, ActorClass::Service, ActorState::Active, 3, 3, $identity->directory()->find($actor->id));
    }

    #[Test]
    public function it_keeps_actors_apart(): void
    {
        $identity = $this->identity(new FakeClock);
        $first = $identity->addActor(ActorClass::Staff);
        $second = $identity->addActor(ActorClass::EndUser);

        $identity->changeState($first->id, ActorState::Deactivated);

        Assert::assertFalse($first->id->equals($second->id), 'Two actors got the same id.');
        $this->assertActor($second->id, ActorClass::EndUser, ActorState::Active, 1, 1, $identity->directory()->find($second->id));
    }

    #[Test]
    public function a_deprovisioned_actor_never_changes_again(): void
    {
        $identity = $this->identity(new FakeClock);
        $actor = $identity->addActor(ActorClass::Staff);
        $identity->changeState($actor->id, ActorState::Deprovisioned);

        try {
            $identity->changeState($actor->id, ActorState::Active);
            Assert::fail('The seeder reactivated a deprovisioned actor.');
        } catch (InvalidIdentity) {
            $this->assertActor($actor->id, ActorClass::Staff, ActorState::Deprovisioned, 2, 2, $identity->directory()->find($actor->id));
        }
    }

    private function assertActor(ActorId $id, ActorClass $class, ActorState $state, int $version, int $generation, ?Actor $actor): void
    {
        Assert::assertNotNull($actor, sprintf('The directory did not find the actor %s.', $id->toString()));
        Assert::assertTrue($actor->id->equals($id), 'The directory returned another actor.');
        Assert::assertSame(
            [$class, $state, $version, $generation],
            [$actor->class, $actor->state, $actor->version, $actor->credentialGeneration->value],
            'The directory read the actor\'s class, state, version or credential generation wrong.',
        );
    }
}
