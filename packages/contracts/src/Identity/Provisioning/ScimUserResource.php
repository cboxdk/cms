<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;

/**
 * A SCIM user as the CMS holds it: its id, the connection that owns it, its state and its version.
 * active is false once the actor is deactivated, whatever deactivated it.
 */
#[Experimental]
final readonly class ScimUserResource
{
    public function __construct(
        public ScimResourceId $id,
        public ConnectionId $connection,
        public ScimUser $user,
        public ResourceVersion $version,
    ) {}
}
