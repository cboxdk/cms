<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a SCIM call changed (PRD 5.16), and so which commands the CMS committed for it before it
 * answered: one change, or two for a replace that also changes active.
 */
#[Experimental]
enum ScimChange: string
{
    /** A user (actor.register) or a group was created. */
    case Created = 'created';

    /** The attributes of a user or group other than active and members were replaced. */
    case Replaced = 'replaced';

    /** active became false: actor.deactivate with the connection as its source. */
    case Deactivated = 'deactivated';

    /** active became true: actor.reactivate, after the same connection had deactivated the actor. */
    case Reactivated = 'reactivated';

    /** A group's members changed: memberships are updated, grants recomputed and grant.changed sent. */
    case MembersChanged = 'members_changed';

    /** The resource was deleted: actor.deprovision for a user; it then answers 404 and is in no query. */
    case Deleted = 'deleted';
}
