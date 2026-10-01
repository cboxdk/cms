<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;

/**
 * The cases the authorizers' behaviour tests share, over the access world's tree and ALICE, with
 * the roles AccessWorld gives her and those addGrants() adds: desk (entry.create, entry.revise)
 * allowed on NEWS, denied on SPORT and allowed again on FOOTBALL; legal (no permission) on
 * CULTURE; translator (entry.revise) on CULTURE in the locale da; reader (probe.see) on SPORT; and
 * auditor (probe.audit) denied on NEWS.
 */
final class AuthorizationCases
{
    public const string REVISE = 'entry.revise';

    public const string CREATE = 'entry.create';

    public const string SEE = 'probe.see';

    /**
     * @return array<string, array{CommandName, AuthorizationTarget}>
     */
    public static function permitted(): array
    {
        return [
            'an allow on the node' => [new CommandName(self::REVISE), self::target(AccessWorld::NEWS)],
            'an allow below a deny of the same role' => [new CommandName(self::REVISE), self::target(AccessWorld::FOOTBALL)],
            'an allow for every locale, in a locale' => [new CommandName(self::REVISE), self::target(AccessWorld::NEWS, 'en')],
            'a grant in the target\'s locale' => [new CommandName(self::REVISE), self::target(AccessWorld::CULTURE, 'da')],
            'a grant in one locale, for a target in no locale' => [new CommandName(self::REVISE), self::target(AccessWorld::CULTURE)],
            'another permission of the role' => [new CommandName(self::CREATE), self::target(AccessWorld::NEWS)],
        ];
    }

    /**
     * @return array<string, array{CommandName, AuthorizationTarget}>
     */
    public static function refused(): array
    {
        return [
            'a deny on the node' => [new CommandName(self::REVISE), self::target(AccessWorld::SPORT)],
            'a grant in another locale than the target\'s' => [new CommandName(self::REVISE), self::target(AccessWorld::CULTURE, 'en')],
            'a node the actor does not reach' => [new CommandName(self::REVISE), self::target(AccessWorld::ROOT)],
            'a node reached through a role without the permission' => [new CommandName(self::CREATE), self::target(AccessWorld::CULTURE)],
            'a permission no role of the actor carries' => [new CommandName('placement.create'), self::target(AccessWorld::NEWS)],
        ];
    }

    /**
     * @return array<string, CommandName>
     */
    public static function refusedQueries(): array
    {
        return [
            'a read whose only grant denies' => new CommandName('probe.audit'),
            'a read no role of the actor names' => new CommandName('probe.unknown'),
        ];
    }

    private static function target(string $node, ?string $locale = null): AuthorizationTarget
    {
        return new AuthorizationTarget(NodeId::fromString($node), $locale === null ? null : new Locale($locale));
    }
}
