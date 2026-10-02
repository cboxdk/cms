<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;

/**
 * A SCIM group as the CMS holds it: its id, the connection that owns it, its state and its version.
 */
#[Experimental]
final readonly class ScimGroupResource
{
    public function __construct(
        public ScimResourceId $id,
        public ConnectionId $connection,
        public ScimGroup $group,
        public ResourceVersion $version,
    ) {}
}
