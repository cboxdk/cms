<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\PermissionRule;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * The kernel's CommandAuthorizer (PRD 5.10, 6.2 phase 2): a command is allowed only through a role
 * of the actor whose permissions (role_permissions) name the command and that reaches every node
 * the action's Aggregates name in authorizationScope(), each in its locale, as the PermissionRule
 * decides. Content rights are held to the entry's home and placement rights to the placement's
 * node, because the actions name those. The anonymous principal holds no role and is refused. An
 * actor that acts on behalf of others gets the intersection of its rights and theirs (PRD 5.16):
 * the actor and every actor of its chain must each pass the rule with their own grants.
 *
 * It reads the actor's grants of those roles and the paths of the target nodes on the default
 * connection, or the one named, inside the command transaction and under its actor context, so a
 * node the context may not read is not reached. Row level security stays the backstop for every
 * row the command then reads and writes.
 */
#[Internal]
final readonly class PostgresCommandAuthorizer implements CommandAuthorizer
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
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates): Authorization
    {
        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal) {
            return Authorization::refuse(sprintf('The anonymous principal holds no role, so it may not run %s.', $command->value));
        }

        $scope = $aggregates->authorizationScope();
        $grants = new PostgresGrants($this->connections, $this->connection);
        $paths = $grants->paths(array_map(static fn (AuthorizationTarget $target): NodeId => $target->node, $scope->targets));
        $authorization = $this->decide($command, $scope, $grants->of($principal->actor, $command), $paths, 'the actor');

        foreach ($principal->onBehalfOf as $delegator) {
            if (! $authorization->allowed()) {
                break;
            }

            $authorization = $this->decide($command, $scope, $grants->ofDelegator($delegator, $command), $paths, sprintf('the actor %s it acts on behalf of', $delegator->toString()));
        }

        return $authorization;
    }

    /**
     * @param  list<Grant>  $permitted
     * @param  array<string, NodePath>  $paths
     */
    private function decide(CommandName $command, AuthorizationScope $scope, array $permitted, array $paths, string $whose): Authorization
    {
        if ($permitted === []) {
            return Authorization::refuse(sprintf('No role of %s may run %s.', $whose, $command->value));
        }

        return $this->rule->command($command, $scope, $permitted, $paths);
    }
}
