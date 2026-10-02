<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimProvisioning;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimResourceId;

/**
 * What the shared suite ScimProvisioningContract needs: the provisioning under test, with no users
 * or groups, and a way to deactivate one of its users through another source than SCIM, as a
 * security event, a local command or the inactivity rule does (PRD 5.16). A harness for the real
 * SCIM server gives it an empty database and runs actor.deactivate with another source.
 */
#[Experimental]
interface ScimProvisioningHarness
{
    /**
     * The provisioning under test. Every call gives the same one.
     */
    public function provisioning(): ScimProvisioning;

    /**
     * Deactivates the user through another source than the connection's SCIM.
     */
    public function deactivateElsewhere(ConnectionId $connection, ScimResourceId $user): void;
}
