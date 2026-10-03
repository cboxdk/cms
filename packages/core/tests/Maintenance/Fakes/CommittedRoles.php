<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance\Fakes;

use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\RoleCreated;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrants;
use Cbox\Cms\Core\Access\Domain\Dto\StoredGrant;
use Cbox\Cms\Core\Access\Domain\Dto\StoredRole;
use Cbox\Cms\Core\Access\Domain\GrantReader;
use Cbox\Cms\Core\Access\Domain\GrantSlotRef;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeGrantReader;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Override;

/**
 * The FakeGrantReader, which also knows each role a RoleCreated the committer committed creates,
 * as the database would after the commit.
 */
final readonly class CommittedRoles implements GrantReader
{
    public function __construct(
        private FakeGrantReader $reader,
        private FakeChangesetCommitter $committer,
    ) {}

    #[Override]
    public function grant(GrantId $grant): ?StoredGrant
    {
        return $this->reader->grant($grant);
    }

    #[Override]
    public function role(RoleId $role): ?StoredRole
    {
        foreach ($this->committer->pending as $pending) {
            foreach ($pending->plan->mutations() as $mutation) {
                if ($mutation instanceof RoleCreated && $mutation->role->equals($role)) {
                    return new StoredRole($role, $mutation->ceiling, $mutation->permissions, AggregateVersion::first());
                }
            }
        }

        return $this->reader->role($role);
    }

    #[Override]
    public function held(GrantSlotRef $slot): bool
    {
        return $this->reader->held($slot);
    }

    #[Override]
    public function roleGrants(RoleId $role): RoleGrants
    {
        return $this->reader->roleGrants($role);
    }

    #[Override]
    public function handleTaken(RoleHandle $handle): bool
    {
        return $this->reader->handleTaken($handle);
    }

    #[Override]
    public function actorGrants(array $actors): array
    {
        return $this->reader->actorGrants($actors);
    }
}
