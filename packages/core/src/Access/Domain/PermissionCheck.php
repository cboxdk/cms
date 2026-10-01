<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;

/**
 * Whether a permission holds (PRD 5.10), from the actor's grants of the roles whose permissions
 * name it, by the rules the AccessCompiler compiles regions by: a role reaches a node when its
 * grant nearest above the node, or on it, allows, a deny beating the allow it inherits.
 *
 * On a node in a locale, only the grants that hold in that locale count, so a deny for that locale
 * wins and an allow for another locale does not reach it; without a locale, the permission holds
 * on a node one of the roles reaches in some locale. Anywhere, it holds when the roles reach a
 * node at all.
 */
#[Internal]
final readonly class PermissionCheck
{
    public function __construct(private AccessCompiler $compiler) {}

    /**
     * @param  list<Grant>  $grants  the actor's grants of the roles whose permissions name it
     */
    public function holdsOn(ActorPrincipal $principal, array $grants, NodePath $node, ?Locale $locale): bool
    {
        if ($locale instanceof Locale) {
            $grants = array_values(array_map(
                static fn (Grant $grant): Grant => new Grant($grant->role, $grant->roleCeiling, $grant->node, $grant->effect),
                array_filter($grants, static fn (Grant $grant): bool => $grant->holdsIn($locale)),
            ));
        }

        return $this->compiler->compile($principal, $grants)->reaches($node);
    }

    /**
     * @param  list<Grant>  $grants  the actor's grants of the roles whose permissions name it
     */
    public function holdsAnywhere(ActorPrincipal $principal, array $grants): bool
    {
        return $this->compiler->compile($principal, $grants)->regions !== [];
    }
}
