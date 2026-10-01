<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;

/**
 * The kernel's rule for which commands and reads an actor may run (PRD 5.10, 6.2): a command or
 * read is allowed only through a role whose permissions name it, so the grants this rule is given
 * are the actor's grants of those roles alone.
 *
 * A role reaches a node in a locale as the AccessCompiler decides it: among the role's grants that
 * hold in the locale, the one nearest above the node, or on it, decides, a deny winning over an
 * allow on the same node. A target without a locale, a command that acts in every locale, must be
 * reached in every locale: in each locale a grant names, and in the others, where only the grants
 * that hold in every locale count. A grant limited to some locales therefore never reaches content
 * shared by all of them alone, and a deny limited to one locale keeps it out. A command is allowed when every target of its scope is reached by
 * one such role; a scope that names no target, and a read, need one such role that reaches some
 * node in some locale.
 */
#[Internal]
final readonly class PermissionRule
{
    /**
     * @param  list<Grant>  $grants  the actor's grants of the roles whose permissions name the command
     * @param  array<string, NodePath>  $paths  the path of each target's node by its id; a target whose
     *                                          node is missing is not reached
     */
    public function command(CommandName $command, AuthorizationScope $scope, array $grants, array $paths): Authorization
    {
        if ($scope->isAnywhere()) {
            return $this->anywhere($command, $grants);
        }

        foreach ($scope->targets as $target) {
            $path = $paths[$target->node->toString()] ?? null;

            if (! $path instanceof NodePath || ! $this->reaches($grants, $path, $target->locale)) {
                return Authorization::refuse(sprintf(
                    'No role of the actor that may run %s reaches the node %s %s.',
                    $command->value,
                    $target->node->toString(),
                    $target->locale instanceof Locale ? 'in the locale '.$target->locale->value : 'in every locale',
                ));
            }
        }

        return Authorization::allow();
    }

    /**
     * @param  list<Grant>  $grants  the actor's grants of the roles whose permissions name the read
     */
    public function query(CommandName $query, array $grants): Authorization
    {
        return $this->anywhere($query, $grants);
    }

    /**
     * Whether a role of the grants reaches the node in the locale. For null, in every locale: in
     * each locale a grant names, and in the locales no grant names, where only the grants that hold
     * in every locale count.
     *
     * @param  list<Grant>  $grants
     */
    public function reaches(array $grants, NodePath $node, ?Locale $locale): bool
    {
        if ($locale instanceof Locale) {
            return $this->reachesWith(array_filter($grants, static fn (Grant $grant): bool => $grant->holdsIn($locale)), $node);
        }

        if (! $this->reachesWith(array_filter($grants, static fn (Grant $grant): bool => $grant->locales === null), $node)) {
            return false;
        }

        foreach ($grants as $grant) {
            foreach ($grant->locales ?? [] as $named) {
                if (! $this->reaches($grants, $node, $named)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<int, Grant>  $grants  the grants that hold in one locale
     */
    private function reachesWith(array $grants, NodePath $node): bool
    {
        $byRole = [];

        foreach ($grants as $grant) {
            $byRole[$grant->role->toString()][] = $grant;
        }

        return array_any(array_keys($byRole), fn (string $role): bool => $this->nearest($byRole[$role], $node) === GrantEffect::Allow);
    }

    /**
     * @param  list<Grant>  $grants
     */
    private function anywhere(CommandName $name, array $grants): Authorization
    {
        $everyLocale = array_filter($grants, static fn (Grant $grant): bool => $grant->locales === null);
        $named = [];

        foreach ($grants as $grant) {
            foreach ($grant->locales ?? [] as $locale) {
                $named[$locale->value] = $locale;
            }
        }

        foreach ($grants as $grant) {
            if ($grant->effect !== GrantEffect::Allow) {
                continue;
            }

            if ($this->reachesWith($everyLocale, $grant->node)) {
                return Authorization::allow();
            }

            foreach ($named as $locale) {
                if ($this->reaches($grants, $grant->node, $locale)) {
                    return Authorization::allow();
                }
            }
        }

        return Authorization::refuse(sprintf('No role of the actor may run %s on any node.', $name->value));
    }

    /**
     * The effect of the grant nearest above the node, or on it, a deny winning over an allow on the
     * same node; null without one.
     *
     * @param  list<Grant>  $grants  the grants of one role that hold in the locale
     */
    private function nearest(array $grants, NodePath $node): ?GrantEffect
    {
        $nearest = null;
        $depth = -1;

        foreach ($grants as $grant) {
            if (! $grant->node->contains($node)) {
                continue;
            }

            $grantDepth = substr_count($grant->node->value, '.');

            if ($grantDepth > $depth) {
                $nearest = $grant->effect;
                $depth = $grantDepth;
            } elseif ($grantDepth === $depth && $grant->effect === GrantEffect::Deny) {
                $nearest = GrantEffect::Deny;
            }
        }

        return $nearest;
    }
}
