<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The SCIM resource types the CMS serves (RFC 7643 4): users, which are staff actors, and groups,
 * whose memberships the group-to-role mapping turns into grants (PRD 5.16).
 */
#[Experimental]
enum ScimResourceType: string
{
    case User = 'User';
    case Group = 'Group';
}
