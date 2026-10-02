<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;
use Cbox\Cms\Contracts\Pipeline\PublicQuery;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Access\Domain\PermissionRule;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * The kernel's QueryAuthorizer (PRD 5.10, 6.2): a PublicQuery, such as path.resolve, may be run by
 * anyone, the anonymous principal included (invariant 25); an ActorQuery, such as node.list, by
 * every actor and not the anonymous principal; any other read only by an actor with a
 * role whose permissions (role_permissions, where reads share the commands' names) name it and that
 * reaches some node, as the PermissionRule decides; an actor that acts on behalf of others only
 * when every actor of its chain may run it too (PRD 5.16). What rows the read reaches is not its
 * question: row level security under the context decides that.
 *
 * It reads the actor's grants of those roles on the default connection, or the one named, inside
 * the read transaction and under its actor context.
 */
#[Internal]
final readonly class PostgresQueryAuthorizer implements QueryAuthorizer
{
    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private PermissionRule $rule,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function authorize(AccessContext $access, CommandName $query, Query $input): Authorization
    {
        if ($input instanceof PublicQuery) {
            return Authorization::allow();
        }

        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal) {
            return Authorization::refuse(sprintf('The anonymous principal may run only public reads, and %s is not one.', $query->value));
        }

        if ($input instanceof ActorQuery) {
            return Authorization::allow();
        }

        $grants = new PostgresGrants($this->connections, $this->connection);
        $authorization = $this->rule->query($query, $grants->of($principal->actor, $query));

        foreach ($principal->onBehalfOf as $delegator) {
            if (! $authorization->allowed()) {
                break;
            }

            $authorization = $this->rule->query($query, $grants->ofDelegator($delegator, $query));
        }

        return $authorization;
    }
}
