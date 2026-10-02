<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The tenant a connection to an issuer with several tenants is pinned to (PRD 5.16): the claim of
 * the token that carries the tenant, and the tenant it must carry.
 */
#[Experimental]
final readonly class TenantPin
{
    public function __construct(public TenantClaim $claim, public TenantId $tenant) {}
}
