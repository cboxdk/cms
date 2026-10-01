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
 * fake authorizers: one actor, and one role per handle.
 */
final class AccessWorldPermissions
{
    public const string ACTOR = '0192a0c0-0000-7000-8000-0000000000c9';

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
        $paths = array_map(static fn (array $ids): NodePath => new NodePath(AccessWorld::path(...$ids)), $tree);
        $actor = ActorId::fromString(self::ACTOR);
        $permissions = new FakePermissions($paths);
        $roles = [];

        foreach ($grants as [$handle, $names, $node, $effect, $locales]) {
            $roles[$handle] ??= RoleId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 900 + count($roles)));
            $permissions->grant(
                $actor,
                new Grant(
                    $roles[$handle],
                    ClassificationAccess::Internal,
                    $paths[$node],
                    $effect,
                    $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales),
                ),
                array_map(static fn (string $name): CommandName => new CommandName($name), $names),
            );
        }

        return [new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Internal), $permissions];
    }
}
