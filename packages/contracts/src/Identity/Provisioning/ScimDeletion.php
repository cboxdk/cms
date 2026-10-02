<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The answer to a SCIM DELETE (RFC 7644 3.6): the resource that was deleted and the change's
 * idempotency key. For a user it is actor.deprovision: like a deactivation, and the link to the IdP
 * identity is removed, while the actor, its subject and its audit are kept. The resource then
 * answers 404 and is in no query, so a repeated DELETE is scim_resource_not_found and has no effect.
 */
#[Experimental]
final readonly class ScimDeletion
{
    public function __construct(public ScimResourceType $type, public ScimResourceId $id, public ScimIdempotencyKey $key) {}
}
