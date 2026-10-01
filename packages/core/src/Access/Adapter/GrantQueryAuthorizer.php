<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\PublicQuery;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Access\Domain\PermissionCheck;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Override;

/**
 * The kernel's QueryAuthorizer (PRD 5.10, 6.2 "Læsninger", invariant 25). A PublicQuery, a read of
 * published content, runs for anyone: the anonymous principal and every actor. Any other read runs
 * for an actor that holds its permission somewhere: a role whose role_permissions name the query
 * and whose grants reach at least one node. Which rows the read reaches is not decided here; row
 * level security under the context does. It refuses the anonymous principal every other read. It
 * reads the grants through PostgresPermissions in the read's transaction.
 */
#[Internal]
final readonly class GrantQueryAuthorizer implements QueryAuthorizer
{
    public function __construct(
        private PostgresPermissions $permissions,
        private PermissionCheck $check,
    ) {}

    #[Override]
    public function authorize(AccessContext $access, CommandName $query, Query $input): Authorization
    {
        if ($input instanceof PublicQuery) {
            return Authorization::allow();
        }

        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal) {
            return Authorization::refuse(sprintf('The anonymous principal runs only public reads, not %s.', $query->value));
        }

        return $this->check->holdsAnywhere($principal, $this->permissions->grants($principal->actor, $query))
            ? Authorization::allow()
            : Authorization::refuse(sprintf('No role of the actor whose permissions name %s reaches a node.', $query->value));
    }
}
