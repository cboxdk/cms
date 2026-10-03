<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Adapter\GrantRows;
use Cbox\Cms\Core\Access\Domain\Dto\ListedGrant;
use Cbox\Cms\Core\Access\Domain\Dto\ListedRole;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedActor;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedProfile;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Dto\ListedNode;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessListings;
use Cbox\Cms\Core\Tests\Identity\Fakes\FakeActorListing;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Core\Tests\Structure\Fakes\FakeNodeListing;
use LogicException;

/**
 * The world of the access queries (PRD 5.8, 5.10, 5.16, 12.2): a site's tree, four actors with
 * profiles, two roles and five grants, as the listing ports read them, written to Postgres as the
 * superuser by seed() and given to the fakes by the readers' contexts.
 *
 * The tree: the site root ROOT of the site north, with the route / in da; NEWS below it with the
 * route /nyheder; SPORT below NEWS and CULTURE, a list, below the root, neither with a route. In
 * tree order: ROOT, NEWS, SPORT, CULTURE, labelled north, north/nyheder, north/nyheder/section and
 * north/list. The role admin (ceiling personal) may run actor.list, grant.list and role.list; the
 * role desk (ceiling internal) actor.list, entry.revise and grant.list. ADMIN holds admin on ROOT,
 * so she reaches every node with personal access. EDITOR holds desk on NEWS, in da and en at
 * version 2, and is denied it on SPORT, so she reaches NEWS alone with internal access. BOB holds
 * desk on CULTURE and held it on SPORT, a grant that has ended; he has no profile. SERVICE, a
 * service actor with a profile, holds nothing, so it reads no grant, no actor and no node.
 */
final class ListingWorld
{
    public const string ROOT = '0192a0c0-0000-7000-8000-000000000301';

    public const string NEWS = '0192a0c0-0000-7000-8000-000000000302';

    public const string SPORT = '0192a0c0-0000-7000-8000-000000000303';

    public const string CULTURE = '0192a0c0-0000-7000-8000-000000000304';

    public const string SITE = '0192a0c0-0000-7000-8000-000000000311';

    public const string ADMIN = '0192a0c0-0000-7000-8000-000000000321';

    public const string EDITOR = '0192a0c0-0000-7000-8000-000000000322';

    public const string BOB = '0192a0c0-0000-7000-8000-000000000323';

    public const string SERVICE = '0192a0c0-0000-7000-8000-000000000324';

    public const string ADMIN_ROLE = '0192a0c0-0000-7000-8000-000000000331';

    public const string DESK = '0192a0c0-0000-7000-8000-000000000332';

    public const string GRANT_ADMIN = '0192a0c0-0000-7000-8000-000000000341';

    public const string GRANT_EDITOR = '0192a0c0-0000-7000-8000-000000000342';

    public const string GRANT_BOB = '0192a0c0-0000-7000-8000-000000000343';

    public const string GRANT_DENIED = '0192a0c0-0000-7000-8000-000000000344';

    public const string GRANT_ENDED = '0192a0c0-0000-7000-8000-000000000345';

    public const string CHANGESET = '019cd79e-4600-7000-8000-000000000391';

    public const string CREATED_AT = '2026-03-10 12:00:00+00';

    /**
     * What each reader's context gives: the nodes its regions reach, in tree order, whether its
     * classification access allows personal, and whether it holds grant.list and actor.list. Every
     * role of the world may run grant.list, so a reader that holds it may run it on every node it
     * reaches; GrantListPermissionTest covers a reader with a role that may not.
     *
     * @var array<string, array{list<string>, bool, bool}>
     */
    public const array READERS = [
        self::ADMIN => [[self::ROOT, self::NEWS, self::SPORT, self::CULTURE], true, true],
        self::EDITOR => [[self::NEWS], false, true],
        self::SERVICE => [[], false, false],
    ];

    /**
     * The profiles, by actor.
     *
     * @var array<string, array{string, string}>
     */
    public const array PROFILES = [
        self::ADMIN => ['Ada Admin', 'ada@example.com'],
        self::EDITOR => ['Eve Editor', 'eve@example.com'],
        self::SERVICE => ['Sync Service', 'sync@example.com'],
    ];

    public static function principal(string $actor): ActorPrincipal
    {
        return new ActorPrincipal(ActorId::fromString($actor), [], IssuerKind::Service, ClassificationAccess::Sensitive);
    }

    /**
     * The roles in the order of their ids, each with its permissions sorted.
     *
     * @return list<ListedRole>
     */
    public static function roles(): array
    {
        return [
            new ListedRole(RoleId::fromString(self::ADMIN_ROLE), new RoleHandle('admin'), ClassificationAccess::Personal, self::names(['actor.list', 'grant.list', 'role.list']), AggregateVersion::first()),
            new ListedRole(RoleId::fromString(self::DESK), new RoleHandle('desk'), ClassificationAccess::Internal, self::names(['actor.list', 'entry.revise', 'grant.list']), AggregateVersion::first()),
        ];
    }

    /**
     * Every grant in the order of its ids with every profile, and whether it has ended.
     *
     * @return list<array{ListedGrant, bool}>
     */
    public static function grants(): array
    {
        return [
            [self::grant(self::GRANT_ADMIN, self::ADMIN, self::ADMIN_ROLE, 'admin', self::ROOT, 'north', GrantEffect::Allow, null, 1), false],
            [self::grant(self::GRANT_EDITOR, self::EDITOR, self::DESK, 'desk', self::NEWS, 'north/nyheder', GrantEffect::Allow, ['da', 'en'], 2), false],
            [self::grant(self::GRANT_BOB, self::BOB, self::DESK, 'desk', self::CULTURE, 'north/list', GrantEffect::Allow, null, 1), false],
            [self::grant(self::GRANT_DENIED, self::EDITOR, self::DESK, 'desk', self::SPORT, 'north/nyheder/section', GrantEffect::Deny, null, 1), false],
            [self::grant(self::GRANT_ENDED, self::BOB, self::DESK, 'desk', self::SPORT, 'north/nyheder/section', GrantEffect::Allow, null, 1), true],
        ];
    }

    /**
     * Every actor in the order of its ids, with its class and every profile.
     *
     * @return list<array{ListedActor, bool}> each with whether it is a staff actor
     */
    public static function actors(): array
    {
        return array_map(
            static fn (string $actor): array => [new ListedActor(ActorId::fromString($actor), ActorState::Active, AggregateVersion::first(), self::profile($actor)), $actor !== self::SERVICE],
            [self::ADMIN, self::EDITOR, self::BOB, self::SERVICE],
        );
    }

    /**
     * Every node in tree order.
     *
     * @return list<ListedNode>
     */
    public static function nodes(): array
    {
        $site = SiteId::fromString(self::SITE);
        $handle = new SiteHandle('north');

        return [
            new ListedNode(NodeId::fromString(self::ROOT), null, NodeKind::Site, $site, $handle, 'north'),
            new ListedNode(NodeId::fromString(self::NEWS), NodeId::fromString(self::ROOT), NodeKind::Section, $site, $handle, 'north/nyheder'),
            new ListedNode(NodeId::fromString(self::SPORT), NodeId::fromString(self::NEWS), NodeKind::Section, $site, $handle, 'north/nyheder/section'),
            new ListedNode(NodeId::fromString(self::CULTURE), NodeId::fromString(self::ROOT), NodeKind::List, $site, $handle, 'north/list'),
        ];
    }

    public static function profile(string $actor): ?ListedProfile
    {
        $profile = self::PROFILES[$actor] ?? null;

        return $profile === null ? null : new ListedProfile(new DisplayName($profile[0]), new EmailAddress($profile[1]));
    }

    /**
     * The AccessListings in memory as the reader's context reads it.
     */
    public static function accessListings(string $reader): FakeAccessListings
    {
        [$reached, $personal, $holds] = self::reader($reader);
        $listings = new FakeAccessListings(ActorId::fromString($reader), $holds ? array_map(NodeId::fromString(...), $reached) : [], $personal);

        foreach (self::roles() as $role) {
            $listings->addRole($role);
        }

        foreach (self::grants() as [$grant, $ended]) {
            $listings->addGrant($grant, $ended);
        }

        return $listings;
    }

    /**
     * The ActorListing in memory as the reader's context reads it.
     */
    public static function actorListing(string $reader): FakeActorListing
    {
        [, $personal, $holds] = self::reader($reader);
        $listing = new FakeActorListing(ActorId::fromString($reader), $personal, $holds);

        foreach (self::actors() as [$actor, $staff]) {
            $listing->add($actor, $staff);
        }

        return $listing;
    }

    /**
     * The NodeListing in memory as the reader's context reads it.
     */
    public static function nodeListing(string $reader): FakeNodeListing
    {
        [$reached] = self::reader($reader);
        $listing = new FakeNodeListing;

        foreach (self::nodes() as $node) {
            $listing->add($node, in_array($node->id->toString(), $reached, true));
        }

        return $listing;
    }

    /**
     * Writes the world to Postgres as the superuser.
     */
    public static function seed(): void
    {
        $superuser = StorageTables::superuser();

        foreach ([
            [self::ROOT, null, 'site', [self::ROOT]],
            [self::NEWS, self::ROOT, 'section', [self::ROOT, self::NEWS]],
            [self::SPORT, self::NEWS, 'section', [self::ROOT, self::NEWS, self::SPORT]],
            [self::CULTURE, self::ROOT, 'list', [self::ROOT, self::CULTURE]],
        ] as [$id, $parent, $kind, $path]) {
            $superuser->table('nodes')->insert([
                'id' => $id,
                'parent_id' => $parent,
                'kind' => $kind,
                'path' => implode('.', array_map(StorageTables::label(...), $path)),
                'version' => 1,
                'created_at' => self::CREATED_AT,
            ]);
        }

        $superuser->table('sites')->insert(['id' => self::SITE, 'handle' => 'north', 'root_node_id' => self::ROOT, 'version' => 1, 'created_at' => self::CREATED_AT]);
        $superuser->table('site_locales')->insert(['site_id' => self::SITE, 'locale' => 'da', 'created_at' => self::CREATED_AT]);
        $superuser->table('node_routes')->insert(['site_id' => self::SITE, 'locale' => 'da', 'route' => '/', 'node_id' => self::ROOT, 'created_at' => self::CREATED_AT]);
        $superuser->table('node_routes')->insert(['site_id' => self::SITE, 'locale' => 'da', 'route' => '/nyheder', 'node_id' => self::NEWS, 'created_at' => self::CREATED_AT]);

        foreach (self::actors() as [$actor, $staff]) {
            $superuser->table('actors')->insert(['id' => $actor->id->toString(), 'actor_class' => $staff ? 'staff' : 'service', 'state' => 'active', 'version' => 1, 'credential_generation' => 1, 'created_at' => self::CREATED_AT]);
        }

        foreach (self::PROFILES as $actor => [$name, $email]) {
            $superuser->table('actor_profiles')->insert(['actor_id' => $actor, 'display_name' => $name, 'email' => $email, 'version' => 1]);
        }

        foreach (self::roles() as $role) {
            $superuser->table('roles')->insert(['id' => $role->id->toString(), 'handle' => $role->handle->value, 'classification_ceiling' => $role->ceiling->value, 'version' => 1, 'created_at' => self::CREATED_AT]);

            foreach ($role->permissions as $permission) {
                $superuser->table('role_permissions')->insert(['role_id' => $role->id->toString(), 'command' => $permission->value, 'created_at' => self::CREATED_AT]);
            }
        }

        $superuser->table('changeset_register')->insert(['changeset_id' => self::CHANGESET, 'retention_class' => 'standard']);

        foreach (self::grants() as [$grant, $ended]) {
            $superuser->table('grants')->insert([
                'id' => $grant->id->toString(),
                'actor_id' => $grant->actor->toString(),
                'role_id' => $grant->role->toString(),
                'node_id' => $grant->node->toString(),
                'effect' => $grant->effect->value,
                'locales' => GrantRows::literal($grant->locales),
                'version' => $grant->version->value,
                'created_at' => self::CREATED_AT,
                'ended_changeset_id' => $ended ? self::CHANGESET : null,
            ]);
        }
    }

    /**
     * @return array{list<string>, bool, bool}
     */
    private static function reader(string $reader): array
    {
        return self::READERS[$reader] ?? throw new LogicException(sprintf('The listing world has no reader %s.', $reader));
    }

    /**
     * @param  list<string>|null  $locales
     */
    private static function grant(string $id, string $actor, string $role, string $handle, string $node, string $label, GrantEffect $effect, ?array $locales, int $version): ListedGrant
    {
        return new ListedGrant(
            GrantId::fromString($id),
            ActorId::fromString($actor),
            self::profile($actor),
            RoleId::fromString($role),
            new RoleHandle($handle),
            NodeId::fromString($node),
            $label,
            $effect,
            $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales),
            new AggregateVersion($version),
        );
    }

    /**
     * @param  list<string>  $names
     * @return list<CommandName>
     */
    private static function names(array $names): array
    {
        return array_map(static fn (string $name): CommandName => new CommandName($name), $names);
    }
}
