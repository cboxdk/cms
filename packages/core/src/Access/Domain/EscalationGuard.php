<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\Dto\HeldGrant;
use Cbox\Cms\Core\Access\Domain\Dto\RoleContentChange;
use Cbox\Cms\Core\Access\Domain\Dto\RoleGrant;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;

/**
 * No escalation (PRD 5.10, invariant 31): an actor can only give the roles it holds itself, on the
 * nodes where it holds them. The authorize step of a command that gives a role asks it after the
 * command's own permission, with the issuing actor's grants and their roles' permissions, and the
 * same for each actor the issuer acts on behalf of.
 *
 * A grant on a node gives the role on the node's whole subtree, so the issuer is held to the whole
 * subtree too. It must itself hold every permission of the role on the node and on every node below
 * it, in each of the grant's locales, or in every locale for a grant without a locale set, as the
 * PermissionRule reaches it: through a role whose permissions name it, whose nearest grant above the
 * node, or on it, allows. A deny of the issuer's below the node, not allowed again deeper by another
 * of its roles, therefore refuses the grant. Its classification access must not be below the role's
 * ceiling anywhere in the subtree: on each node, in each of those locales, the highest ceiling among
 * its roles that reach it, capped by the credential's ceiling. Otherwise the grant is refused with
 * grant_escalation_refused. The issuer's reach changes only on the nodes of its own grants, so the
 * node and the nodes of its grants strictly below it are the nodes that decide the subtree.
 *
 * A role is administrative when one of its permissions is a command, a write, that changes roles,
 * grants, the identity mapping, connections or who is active, as AdministrativePermissions decides
 * from the registry: role.list and grant.list only read, so they do not count. PRD 5.16 requires
 * step-up for a grant of one, and step-up is not built yet, so such a grant is refused with
 * step_up_required whoever gives it; the one-time access bootstrap, in the maintenance process,
 * does not come through here.
 *
 * A command that creates a role or changes its permissions is held to the same rule on the role's
 * content (content() and ceiling()): a role the issuer creates may not read above its own
 * classification access, and each permission a change adds must be the issuer's own on every node
 * where the role is granted, because every holder gets it there. A change that takes permissions
 * away needs the command itself on every node where the role is granted, because every holder
 * loses them there. A change that makes a granted role administrative needs step-up too.
 */
#[Internal]
final readonly class EscalationGuard
{
    public function __construct(
        private AdministrativePermissions $administrative,
        private PermissionRule $rule = new PermissionRule,
    ) {}

    /**
     * Whether the issuer, with the grants it holds, may give the role on the node, whose path is
     * given.
     *
     * @param  list<HeldGrant>  $held  every grant the issuer holds that has not ended
     * @param  ClassificationAccess  $credentialCeiling  the ceiling of the credential the issuer acts with
     * @param  string  $whose  who the issuer is, for the reason: "the actor", or the actor it acts on behalf of
     */
    public function decide(RoleGrant $grant, NodePath $node, array $held, ClassificationAccess $credentialCeiling, string $whose = 'the actor'): Authorization
    {
        $locales = $grant->locales ?? [null];

        foreach ($grant->permissions as $permission) {
            $permitted = $this->permitted($held, $permission);

            foreach ($locales as $locale) {
                $missing = $this->unreached($permitted, $node, $locale);

                if ($missing instanceof NodePath) {
                    return Authorization::refuse(sprintf(
                        'The role %s may run %s, and %s does not hold it %s %s, so it may not give the role there (invariant 31).',
                        $grant->role->toString(),
                        $permission->value,
                        $whose,
                        $this->on($grant->node->toString(), $node, $missing),
                        $this->where($locale),
                    ), ErrorCode::GrantEscalationRefused);
                }
            }
        }

        $access = $this->access($held, $node, $locales)->atMost($credentialCeiling);

        if (! $access->allows($grant->ceiling)) {
            return Authorization::refuse(sprintf(
                'The role %s reads up to %s, above the %s classification access %s has on the node %s or below it, so it may not give the role there (invariant 31).',
                $grant->role->toString(),
                $grant->ceiling->value,
                $access->value,
                $whose,
                $grant->node->toString(),
            ), ErrorCode::GrantEscalationRefused);
        }

        if ($this->administrative->any($grant->permissions)) {
            return Authorization::refuse(sprintf(
                'The role %s is administrative, because it may change roles, grants or who is active, and a grant of it needs step-up (PRD 5.16), which is not built yet; the first administrator gets one from the access bootstrap.',
                $grant->role->toString(),
            ), ErrorCode::StepUpRequired);
        }

        return Authorization::allow();
    }

    /**
     * Whether the issuer, with its classification access, may create a role with the ceiling of
     * the change (PRD 5.10, 12.2): a role it creates may not read above what the issuer reads.
     */
    public function ceiling(RoleContentChange $change, ClassificationAccess $access): Authorization
    {
        if (! $change->ceiling instanceof ClassificationAccess || $access->allows($change->ceiling)) {
            return Authorization::allow();
        }

        return Authorization::refuse(sprintf(
            'The role %s would read up to %s, above the %s classification access of the actor, so it may not create it (invariant 31).',
            $change->role->toString(),
            $change->ceiling->value,
            $access->value,
        ), ErrorCode::GrantEscalationRefused);
    }

    /**
     * Whether the issuer, with the grants it holds, may change what the role gives (PRD 5.10,
     * invariant 31): it must itself hold each permission the change adds on every node where an
     * allow of the role has not ended, and on every node below it, in that grant's locales, or in
     * every locale for a grant without a locale set. A change that takes permissions away is held
     * the same way to the command itself: the issuer must hold it on every such node and every node
     * below it, in those locales, because every holder loses the permissions there, so it never
     * takes rights from holders where it has no authority. A node whose path the issuer may not
     * read is one where it holds nothing. A change that makes a granted role administrative needs
     * step-up, which is not built yet, so it is refused with step_up_required.
     *
     * @param  CommandName  $command  the command that changes the role, role.set_permissions
     * @param  array<string, NodePath>  $paths  the path of each node of the role's grants the issuer may read, by its id
     * @param  list<HeldGrant>  $held  every grant the issuer holds that has not ended
     * @param  string  $whose  who the issuer is, for the reason
     */
    public function content(RoleContentChange $change, CommandName $command, array $paths, array $held, string $whose = 'the actor'): Authorization
    {
        $allows = $change->allows();
        $changing = $this->permitted($held, $command);

        foreach ($allows as $grant) {
            $node = $paths[$grant->node->toString()] ?? null;

            if ($change->removed !== []) {
                if (! $node instanceof NodePath) {
                    return Authorization::refuse(sprintf(
                        'The role %s is granted on a node %s does not reach, so it may not take %s away from the role (invariant 31).',
                        $change->role->toString(),
                        $whose,
                        $change->removed[0]->value,
                    ), ErrorCode::GrantEscalationRefused);
                }

                foreach ($grant->locales ?? [null] as $locale) {
                    $missing = $this->unreached($changing, $node, $locale);

                    if ($missing instanceof NodePath) {
                        return Authorization::refuse(sprintf(
                            'The role %s is granted on the node %s, and %s may not run %s %s %s, so it may not take %s away from the role (invariant 31).',
                            $change->role->toString(),
                            $grant->node->toString(),
                            $whose,
                            $command->value,
                            $this->on($grant->node->toString(), $node, $missing),
                            $this->where($locale),
                            $change->removed[0]->value,
                        ), ErrorCode::GrantEscalationRefused);
                    }
                }
            }

            foreach ($change->added as $permission) {
                if (! $node instanceof NodePath) {
                    return Authorization::refuse(sprintf(
                        'The role %s is granted on a node %s does not reach, so it may not add %s to the role (invariant 31).',
                        $change->role->toString(),
                        $whose,
                        $permission->value,
                    ), ErrorCode::GrantEscalationRefused);
                }

                $permitted = $this->permitted($held, $permission);

                foreach ($grant->locales ?? [null] as $locale) {
                    $missing = $this->unreached($permitted, $node, $locale);

                    if ($missing instanceof NodePath) {
                        return Authorization::refuse(sprintf(
                            'The role %s is granted on the node %s, and %s does not hold %s %s %s, so it may not add it to the role (invariant 31).',
                            $change->role->toString(),
                            $grant->node->toString(),
                            $whose,
                            $permission->value,
                            $this->on($grant->node->toString(), $node, $missing),
                            $this->where($locale),
                        ), ErrorCode::GrantEscalationRefused);
                    }
                }
            }
        }

        if ($allows !== [] && $this->becomesAdministrative($change)) {
            return Authorization::refuse(sprintf(
                'The change makes the granted role %s administrative, because it could then change roles, grants or who is active, and that needs step-up (PRD 5.16), which is not built yet.',
                $change->role->toString(),
            ), ErrorCode::StepUpRequired);
        }

        return Authorization::allow();
    }

    /**
     * Whether the change makes a role that was not administrative administrative: the role held no
     * administrative permission and the change adds one. A permission it takes away cannot make it
     * so, and a role that was administrative already is not made so again.
     */
    public function becomesAdministrative(RoleContentChange $change): bool
    {
        return ! $this->administrative->any($change->previous) && $this->administrative->any($change->added);
    }

    /**
     * The grants of the roles whose permissions name the permission.
     *
     * @param  list<HeldGrant>  $held
     * @return list<Grant>
     */
    private function permitted(array $held, CommandName $permission): array
    {
        $grants = [];

        foreach ($held as $grant) {
            if ($grant->permits($permission)) {
                $grants[] = $grant->grant;
            }
        }

        return $grants;
    }

    /**
     * The first node of the subtree below the node, the node included, that the grants do not
     * reach in the locale; null when they reach all of it. The reach of the grants changes only on
     * their own nodes, so the node and the nodes of the grants strictly below it decide.
     *
     * @param  list<Grant>  $grants
     */
    private function unreached(array $grants, NodePath $node, ?Locale $locale): ?NodePath
    {
        foreach ($this->deciding($grants, $node) as $point) {
            if (! $this->rule->reaches($grants, $point, $locale)) {
                return $point;
            }
        }

        return null;
    }

    /**
     * The node, then the nodes of the grants strictly below it, each once, by path.
     *
     * @param  list<Grant>  $grants
     * @return list<NodePath>
     */
    private function deciding(array $grants, NodePath $node): array
    {
        $points = [$node->value => $node];

        foreach ($grants as $grant) {
            if ($node->isAbove($grant->node)) {
                $points[$grant->node->value] ??= $grant->node;
            }
        }

        $below = array_slice($points, 1);
        ksort($below, SORT_STRING);

        return [$node, ...array_values($below)];
    }

    /**
     * The issuer's classification access on the node's subtree: on each node of it, in each
     * locale, the highest ceiling among its roles that reach the node there, and the lowest of
     * those over the nodes and the locales; public when one has none.
     *
     * @param  list<HeldGrant>  $held
     * @param  list<Locale|null>  $locales
     */
    private function access(array $held, NodePath $node, array $locales): ClassificationAccess
    {
        $byRole = [];
        $all = [];

        foreach ($held as $grant) {
            $byRole[$grant->grant->role->toString()][] = $grant->grant;
            $all[] = $grant->grant;
        }

        $lowest = null;

        foreach ($this->deciding($all, $node) as $point) {
            foreach ($locales as $locale) {
                $highest = ClassificationAccess::Public;

                foreach ($byRole as $grants) {
                    if ($grants[0]->roleCeiling->rank() > $highest->rank() && $this->rule->reaches($grants, $point, $locale)) {
                        $highest = $grants[0]->roleCeiling;
                    }
                }

                $lowest = $lowest === null ? $highest : $lowest->atMost($highest);
            }
        }

        return $lowest ?? ClassificationAccess::Public;
    }

    /**
     * Where the issuer lacks a permission, for the reason: on the node, or below it.
     */
    private function on(string $id, NodePath $node, NodePath $missing): string
    {
        return $missing->equals($node)
            ? 'on the node '.$id
            : sprintf('on the node %s below the node %s', $missing->value, $id);
    }

    private function where(?Locale $locale): string
    {
        return $locale instanceof Locale ? 'in the locale '.$locale->value : 'in every locale';
    }
}
