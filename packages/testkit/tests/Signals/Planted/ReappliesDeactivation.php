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
 * A planted provisioning that deactivates again when asked to deactivate a deactivated user: it
 * reactivates and deactivates, so a repeated SCIM request commits a second deactivation.
 */
final readonly class ReappliesDeactivation implements ScimProvisioning, ScimProvisioningHarness
{
    private FakeScimProvisioning $fake;

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
        $this->fake->deactivateElsewhere($connection, $user);
    }

    #[Override]
    public function createUser(ConnectionId $connection, ScimUser $user): ScimUserOutcome
    {
        return $this->fake->createUser($connection, $user);
    }

    #[Override]
    public function replaceUser(ConnectionId $connection, ScimResourceId $id, ScimUser $user, ?ResourceVersion $ifMatch = null): ScimUserOutcome
    {
        return $this->fake->replaceUser($connection, $id, $user, $ifMatch);
    }

    #[Override]
    public function setUserActive(ConnectionId $connection, ScimResourceId $id, bool $active, ?ResourceVersion $ifMatch = null): ScimUserOutcome
    {
        if (! $active && ! $this->fake->user($connection, $id)->user->active) {
            $this->fake->setUserActive($connection, $id, true);
        }

        return $this->fake->setUserActive($connection, $id, $active, $ifMatch);
    }

    #[Override]
    public function deleteUser(ConnectionId $connection, ScimResourceId $id, ?ResourceVersion $ifMatch = null): ScimDeletion
    {
        return $this->fake->deleteUser($connection, $id, $ifMatch);
    }

    #[Override]
    public function user(ConnectionId $connection, ScimResourceId $id): ScimUserResource
    {
        return $this->fake->user($connection, $id);
    }

    #[Override]
    public function createGroup(ConnectionId $connection, ScimGroup $group): ScimGroupOutcome
    {
        return $this->fake->createGroup($connection, $group);
    }

    #[Override]
    public function replaceGroup(ConnectionId $connection, ScimResourceId $id, ScimGroup $group, ?ResourceVersion $ifMatch = null): ScimGroupOutcome
    {
        return $this->fake->replaceGroup($connection, $id, $group, $ifMatch);
    }

    #[Override]
    public function changeMembers(ConnectionId $connection, ScimResourceId $id, MembershipChange $change, ?ResourceVersion $ifMatch = null): ScimGroupOutcome
    {
        return $this->fake->changeMembers($connection, $id, $change, $ifMatch);
    }

    #[Override]
    public function deleteGroup(ConnectionId $connection, ScimResourceId $id, ?ResourceVersion $ifMatch = null): ScimDeletion
    {
        return $this->fake->deleteGroup($connection, $id, $ifMatch);
    }

    #[Override]
    public function group(ConnectionId $connection, ScimResourceId $id): ScimGroupResource
    {
        return $this->fake->group($connection, $id);
    }
}
