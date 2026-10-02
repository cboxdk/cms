<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Signals\Planted;

use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Provisioning\MembershipChange;
use Cbox\Cms\Contracts\Identity\Provisioning\ResourceVersion;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimDeletion;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimGroup;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimGroupOutcome;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimGroupResource;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimProvisioning;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimResourceId;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUser;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUserOutcome;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUserResource;
use Cbox\Cms\Testkit\Provisioning\FakeScimProvisioning;
use Cbox\Cms\Testkit\Provisioning\ScimProvisioningHarness;
use Override;

/**
 * A planted provisioning that addresses a resource by its id alone: a call for a user or group of
 * another connection runs as that connection's, as a SCIM server that checked the token but not
 * whose resource it names would.
 */
final class ReachesEveryConnection implements ScimProvisioning, ScimProvisioningHarness
{
    private readonly FakeScimProvisioning $fake;

    /** @var array<string, ConnectionId> the connection of each resource, by id */
    private array $owners = [];

    public function __construct()
    {
        $this->fake = new FakeScimProvisioning;
    }

    #[Override]
    public function provisioning(): ScimProvisioning
    {
        return $this;
    }

    #[Override]
    public function deactivateElsewhere(ConnectionId $connection, ScimResourceId $user): void
    {
        $this->fake->deactivateElsewhere($this->owner($connection, $user), $user);
    }

    #[Override]
    public function createUser(ConnectionId $connection, ScimUser $user): ScimUserOutcome
    {
        $outcome = $this->fake->createUser($connection, $user);
        $this->owners[$outcome->user->id->value] = $connection;

        return $outcome;
    }

    #[Override]
    public function replaceUser(ConnectionId $connection, ScimResourceId $id, ScimUser $user, ?ResourceVersion $ifMatch = null): ScimUserOutcome
    {
        return $this->fake->replaceUser($this->owner($connection, $id), $id, $user, $ifMatch);
    }

    #[Override]
    public function setUserActive(ConnectionId $connection, ScimResourceId $id, bool $active, ?ResourceVersion $ifMatch = null): ScimUserOutcome
    {
        return $this->fake->setUserActive($this->owner($connection, $id), $id, $active, $ifMatch);
    }

    #[Override]
    public function deleteUser(ConnectionId $connection, ScimResourceId $id, ?ResourceVersion $ifMatch = null): ScimDeletion
    {
        return $this->fake->deleteUser($this->owner($connection, $id), $id, $ifMatch);
    }

    #[Override]
    public function user(ConnectionId $connection, ScimResourceId $id): ScimUserResource
    {
        return $this->fake->user($this->owner($connection, $id), $id);
    }

    #[Override]
    public function createGroup(ConnectionId $connection, ScimGroup $group): ScimGroupOutcome
    {
        $outcome = $this->fake->createGroup($connection, $group);
        $this->owners[$outcome->group->id->value] = $connection;

        return $outcome;
    }

    #[Override]
    public function replaceGroup(ConnectionId $connection, ScimResourceId $id, ScimGroup $group, ?ResourceVersion $ifMatch = null): ScimGroupOutcome
    {
        return $this->fake->replaceGroup($this->owner($connection, $id), $id, $group, $ifMatch);
    }

    #[Override]
    public function changeMembers(ConnectionId $connection, ScimResourceId $id, MembershipChange $change, ?ResourceVersion $ifMatch = null): ScimGroupOutcome
    {
        return $this->fake->changeMembers($this->owner($connection, $id), $id, $change, $ifMatch);
    }

    #[Override]
    public function deleteGroup(ConnectionId $connection, ScimResourceId $id, ?ResourceVersion $ifMatch = null): ScimDeletion
    {
        return $this->fake->deleteGroup($this->owner($connection, $id), $id, $ifMatch);
    }

    #[Override]
    public function group(ConnectionId $connection, ScimResourceId $id): ScimGroupResource
    {
        return $this->fake->group($this->owner($connection, $id), $id);
    }

    private function owner(ConnectionId $caller, ScimResourceId $id): ConnectionId
    {
        return $this->owners[$id->value] ?? $caller;
    }
}
