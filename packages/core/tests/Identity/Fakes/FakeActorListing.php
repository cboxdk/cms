<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity\Fakes;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Identity\Domain\ActorListing;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedActor;
use Override;

/**
 * ActorListing in memory, read as the actor of a context whose classification access does or does
 * not allow personal, and which does or does not hold actor.list (ActorListingBehaviour holds it to
 * PostgresActorListing). A test adds the actors with every profile; staff() gives the staff actors,
 * with a profile only for the reader's own actor or at personal access, and nothing to a reader
 * that does not hold actor.list.
 */
final class FakeActorListing implements ActorListing
{
    /** @var array<string, array{ListedActor, bool}> by id, with whether it is a staff actor */
    private array $actors = [];

    public function __construct(
        private readonly ActorId $reader,
        private readonly bool $personal,
        private readonly bool $holdsActorList,
    ) {}

    public function add(ListedActor $actor, bool $staff = true): self
    {
        $this->actors[$actor->id->toString()] = [$actor, $staff];

        return $this;
    }

    #[Override]
    public function staff(?ActorId $after, int $limit): array
    {
        if (! $this->holdsActorList) {
            return [];
        }

        $actors = $this->actors;
        ksort($actors, SORT_STRING);
        $listed = [];

        foreach ($actors as $id => [$actor, $staff]) {
            if (! $staff || ($after instanceof ActorId && strcmp($id, $after->toString()) <= 0)) {
                continue;
            }

            $listed[] = $this->personal || $actor->id->equals($this->reader) ? $actor : new ListedActor($actor->id, $actor->state, $actor->version, null);
        }

        return array_slice($listed, 0, $limit);
    }
}
