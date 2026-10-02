<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\LoginPolicy;

use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LoginPolicy\Domain\IdpLinks;
use Cbox\Cms\Identity\Tests\LoginPolicy\Fakes\FakeIdpLinks;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * IdpLinksBehaviour against the fake the login policy's tests use.
 */
final class FakeIdpLinksBehaviourTest extends TestCase
{
    use IdpLinksBehaviour;

    private ?FakeIdpLinks $links = null;

    private ?FakeIdGenerator $ids = null;

    #[Override]
    protected function links(): IdpLinks
    {
        return $this->fake();
    }

    #[Override]
    protected function newActor(): ActorId
    {
        $this->ids ??= new FakeIdGenerator;

        return new ActorId($this->ids->next());
    }

    #[Override]
    protected function link(ActorId $actor, IdpIdentity $identity): void
    {
        $this->fake()->link($actor, $identity);
    }

    public function test_it_counts_the_lookups(): void
    {
        $this->fake()->connectionsOf($this->newActor());
        $this->fake()->connectionsOf($this->newActor());

        self::assertSame(2, $this->fake()->reads());
    }

    private function fake(): FakeIdpLinks
    {
        return $this->links ??= new FakeIdpLinks;
    }
}
