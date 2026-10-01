<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Postgres\AccessWorld;

/**
 * The grants of the authorizers' behaviour traits held in memory, over AccessWorld's tree, for the
 * fake authorizers: one actor, one role per handle, and a delegate that acts on behalf of it.
 */
final class AccessWorldPermissions
{
    public const string ACTOR = '0192a0c0-0000-7000-8000-0000000000c9';

    public const string DELEGATE = '0192a0c0-0000-7000-8000-0000000000c7';

    /**
     * @param  list<array{string, list<string>, string, GrantEffect, list<string>|null}>  $grants  role handle, permissions, node, effect, locales
     * @return array{ActorPrincipal, FakePermissions}
     */
    public static function of(array $grants): array
    {
        $tree = [
            AccessWorld::ROOT => [AccessWorld::ROOT],
            AccessWorld::NEWS => [AccessWorld::ROOT, AccessWorld::NEWS],
            AccessWorld::SPORT => [AccessWorld::ROOT, AccessWorld::NEWS, AccessWorld::SPORT],
            AccessWorld::FOOTBALL => [AccessWorld::ROOT, AccessWorld::NEWS, AccessWorld::SPORT, AccessWorld::FOOTBALL],
            AccessWorld::CULTURE => [AccessWorld::ROOT, AccessWorld::CULTURE],
        ];
        $actor = ActorId::fromString(self::ACTOR);
        $permissions = new FakePermissions(array_map(static fn (array $ids): NodePath => new NodePath(AccessWorld::path(...$ids)), $tree));
        self::grant($permissions, $actor, $grants, 900);

        return [new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Internal), $permissions];
    }

    /**
     * Gives DELEGATE the grants, with roles of their own, and its principal on behalf of the person.
     *
     * @param  list<array{string, list<string>, string, GrantEffect, list<string>|null}>  $grants  role handle, permissions, node, effect, locales
     */
    public static function delegateOf(FakePermissions $permissions, ActorPrincipal $person, array $grants): ActorPrincipal
    {
        $delegate = ActorId::fromString(self::DELEGATE);
        self::grant($permissions, $delegate, $grants, 950);

        return new ActorPrincipal($delegate, [$person->actor], IssuerKind::Service, ClassificationAccess::Internal);
    }

    /**
     * @param  list<array{string, list<string>, string, GrantEffect, list<string>|null}>  $grants
     */
    private static function grant(FakePermissions $permissions, ActorId $actor, array $grants, int $firstRole): void
    {
        $roles = [];

        foreach ($grants as [$handle, $names, $node, $effect, $locales]) {
            $roles[$handle] ??= RoleId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', $firstRole + count($roles)));
            $permissions->grant(
                $actor,
                new Grant(
                    $roles[$handle],
                    ClassificationAccess::Internal,
                    $permissions->path($node),
                    $effect,
                    $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales),
                ),
                array_map(static fn (string $name): CommandName => new CommandName($name), $names),
            );
        }
    }
}
