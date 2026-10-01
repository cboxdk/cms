<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\AuthorizedOn;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Access\Domain\PermissionCheck;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;
use Override;

/**
 * The kernel's CommandAuthorizer (PRD 5.10, 6.2 phase 2): a command runs when the actor holds its
 * permission on every node it acts on. A role holds the permission when its role_permissions name
 * the command; the nodes are the AuthorizationTargets the command's aggregates name: an entry's
 * home for a change of its content, a placement's node for a change of the placement. On each the
 * actor's grants of those roles decide by the rules of the access regions (PermissionCheck), in
 * the target's locale when it has one.
 *
 * It refuses the anonymous principal, which runs no command, a command whose aggregates do not
 * implement AuthorizedOn or name no target, and a target the actor does not reach or that no role
 * with the permission reaches. It reads the grants and the nodes' paths through PostgresPermissions
 * in the command transaction, under the context the transaction set, so it never reads a node the
 * actor's regions hide.
 */
#[Internal]
final readonly class GrantCommandAuthorizer implements CommandAuthorizer
{
    public function __construct(
        private PostgresPermissions $permissions,
        private PermissionCheck $check,
    ) {}

    #[Override]
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates): Authorization
    {
        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal) {
            return Authorization::refuse(sprintf('The anonymous principal runs no command; %s needs an actor whose role allows it.', $command->value));
        }

        $targets = $aggregates instanceof AuthorizedOn ? $aggregates->authorizationTargets() : [];

        if ($targets === []) {
            return Authorization::refuse(sprintf('The command %s names no node its permission is checked on, so no grant allows it.', $command->value));
        }

        $grants = $this->permissions->grants($principal->actor, $command);
        $paths = $this->permissions->paths(array_map(static fn (AuthorizationTarget $target): NodeId => $target->node, $targets));

        foreach ($targets as $target) {
            $path = $paths[$target->node->toString()] ?? null;

            if (! $path instanceof NodePath) {
                return Authorization::refuse(sprintf('The actor does not reach the node %s, so it may not run %s there.', $target->node->toString(), $command->value));
            }

            if (! $this->check->holdsOn($principal, $grants, $path, $target->locale)) {
                return Authorization::refuse(sprintf(
                    'No role of the actor whose permissions name %s reaches the node %s%s.',
                    $command->value,
                    $target->node->toString(),
                    $target->locale instanceof Locale ? ' in the locale '.$target->locale->value : '',
                ));
            }
        }

        return Authorization::allow();
    }
}
