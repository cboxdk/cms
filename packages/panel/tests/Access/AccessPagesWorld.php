<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Access;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Access\Actions\AssignGrantAction;
use Cbox\Cms\Core\Access\Actions\CreateRoleAction;
use Cbox\Cms\Core\Access\Actions\ListGrantsAction;
use Cbox\Cms\Core\Access\Actions\ListRolesAction;
use Cbox\Cms\Core\Access\Actions\RevokeGrantAction;
use Cbox\Cms\Core\Access\Actions\SetRolePermissionsAction;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Commands\RevokeGrant;
use Cbox\Cms\Core\Access\Domain\Commands\SetRolePermissions;
use Cbox\Cms\Core\Access\Domain\Dto\GrantList;
use Cbox\Cms\Core\Access\Domain\Dto\RoleList;
use Cbox\Cms\Core\Access\Domain\Queries\ListGrants;
use Cbox\Cms\Core\Access\Domain\Queries\ListRoles;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActorListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\GrantListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\NodeListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\RoleListCodecV1;
use Cbox\Cms\Core\Identity\Actions\ListActorsAction;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorList;
use Cbox\Cms\Core\Identity\Domain\Queries\ListActors;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;
use Cbox\Cms\Core\Registry\Actions\ListActionsAction;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Queries\ListActions;
use Cbox\Cms\Core\Structure\Actions\ListNodesAction;
use Cbox\Cms\Core\Structure\Domain\Dto\NodeList;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessListings;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Core\Tests\Identity\Fakes\FakeActorListing;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Registry\ActionListWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;

/**
 * The roles and grants pages' reads over fakes (GUARDRAILS 9, PRD 5.10, 13.4): the real
 * QueryPipeline of an ActionListWorld with the actions of role.list, grant.list, actor.list and
 * node.list over the ListingWorld's roles, grants, actors and nodes in memory, read as the person
 * who signed in, whose session credential the login world's verifier verifies, with regions over
 * the root at personal classification access and a role on it whose permissions a test gives,
 * the access commands and queries by default; and action.list over a registry that exposes the
 * access commands and queries on REST and Inertia, so the shared prop `palette` says what the
 * person may do. The authorizer decides as the kernel's does from those grants, so a read the
 * person holds no permission for is refused; refusing() gives a pipeline that refuses every read,
 * and without() one that cannot make the reads given, for the pickers' unavailable state.
 */
final class AccessPagesWorld
{
    /** The permissions of the person's role, the access commands and queries. */
    public const array ADMINISTRATION = ['role.list', 'role.create', 'role.set_permissions', 'grant.list', 'grant.assign', 'grant.revoke', 'actor.list'];

    public readonly ActionListWorld $actions;

    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        CredentialVerifier $verifier,
        public ActorId $person,
        FakeClock $clock,
        FakeIdentity $identity,
        array $permissions = self::ADMINISTRATION,
    ) {
        $this->actions = new ActionListWorld(self::registry(), $verifier, $clock, $identity);
        $this->actions->grant($person, $permissions, access: ClassificationAccess::Personal);
    }

    /**
     * The pipeline that answers every read of the pages as the person.
     */
    public function pipeline(): QueryPipeline
    {
        return $this->actions->pipeline(more: $this->bindings());
    }

    /**
     * The pipeline that refuses every read.
     */
    public function refusing(): QueryPipeline
    {
        return $this->actions->pipeline(new FakeQueryAuthorizer('The test refuses every read.'), $this->bindings());
    }

    /**
     * The pipeline that cannot make the reads of the queries given, because no action answers them.
     *
     * @param  list<class-string<Query>>  $queries
     */
    public function without(array $queries): QueryPipeline
    {
        $bindings = $this->bindings();

        foreach ($queries as $query) {
            unset($bindings[$query]);
        }

        return $this->actions->pipeline(more: $bindings);
    }

    /**
     * The roles as role.list answers them, the ListingWorld's in the order of their ids.
     */
    public static function roles(): RoleList
    {
        return new RoleList(ListingWorld::roles(), null);
    }

    /**
     * The grants as grant.list answers them to the person: the ListingWorld's that have not ended,
     * with every profile, because the person reads at personal access.
     */
    public static function grants(): GrantList
    {
        $grants = [];

        foreach (ListingWorld::grants() as [$grant, $ended]) {
            if (! $ended) {
                $grants[] = $grant;
            }
        }

        return new GrantList($grants, null);
    }

    /**
     * The staff actors as actor.list answers them to the person, with every profile.
     */
    public static function actors(): ActorList
    {
        $actors = [];

        foreach (ListingWorld::actors() as [$actor, $staff]) {
            if ($staff) {
                $actors[] = $actor;
            }
        }

        return new ActorList($actors, null);
    }

    /**
     * The nodes as node.list answers them to the person, every node of the tree.
     */
    public static function nodes(): NodeList
    {
        return new NodeList(ListingWorld::nodes(), null);
    }

    /**
     * The JSON of each result as its codec writes it at personal access, by query name.
     *
     * @return array<string, string>
     */
    public static function documents(): array
    {
        return [
            'role.list' => new RoleListCodecV1()->encode(self::roles(), ClassificationAccess::Personal),
            'grant.list' => new GrantListCodecV1()->encode(self::grants(), ClassificationAccess::Personal),
            'actor.list' => new ActorListCodecV1()->encode(self::actors(), ClassificationAccess::Personal),
            'node.list' => new NodeListCodecV1()->encode(self::nodes(), ClassificationAccess::Personal),
        ];
    }

    /**
     * The registry action.list reads: the access commands and queries on REST and Inertia.
     */
    public static function registry(): CompiledRegistry
    {
        $panel = [Surface::Rest, Surface::Inertia];

        return new CompiledRegistry(
            commands: [],
            hooks: [],
            actions: [
                new ActionEntry(ListActionsAction::class, ActionListWorld::PACKAGE, ActionKind::Query, new CommandName('action.list'), 1, ListActions::class, $panel),
                new ActionEntry(ListActorsAction::class, ActionListWorld::PACKAGE, ActionKind::Query, new CommandName('actor.list'), 1, ListActors::class, $panel),
                new ActionEntry(AssignGrantAction::class, ActionListWorld::PACKAGE, ActionKind::Write, new CommandName('grant.assign'), 1, AssignGrant::class, $panel),
                new ActionEntry(ListGrantsAction::class, ActionListWorld::PACKAGE, ActionKind::Query, new CommandName('grant.list'), 1, ListGrants::class, $panel),
                new ActionEntry(RevokeGrantAction::class, ActionListWorld::PACKAGE, ActionKind::Write, new CommandName('grant.revoke'), 1, RevokeGrant::class, $panel),
                new ActionEntry(ListNodesAction::class, ActionListWorld::PACKAGE, ActionKind::Query, new CommandName('node.list'), 1, ListNodes::class, $panel),
                new ActionEntry(CreateRoleAction::class, ActionListWorld::PACKAGE, ActionKind::Write, new CommandName('role.create'), 1, CreateRole::class, $panel),
                new ActionEntry(ListRolesAction::class, ActionListWorld::PACKAGE, ActionKind::Query, new CommandName('role.list'), 1, ListRoles::class, $panel),
                new ActionEntry(SetRolePermissionsAction::class, ActionListWorld::PACKAGE, ActionKind::Write, new CommandName('role.set_permissions'), 1, SetRolePermissions::class, $panel),
            ],
            panel: [],
        );
    }

    /**
     * @return array<class-string<Query>, QueryBinding>
     */
    private function bindings(): array
    {
        $listings = new FakeAccessListings($this->person, array_map(NodeId::fromString(...), [ListingWorld::ROOT, ListingWorld::NEWS, ListingWorld::SPORT, ListingWorld::CULTURE]), true);

        foreach (ListingWorld::roles() as $role) {
            $listings->addRole($role);
        }

        foreach (ListingWorld::grants() as [$grant, $ended]) {
            $listings->addGrant($grant, $ended);
        }

        $actors = new FakeActorListing($this->person, true, true);

        foreach (ListingWorld::actors() as [$actor, $staff]) {
            $actors->add($actor, $staff);
        }

        return [
            ListRoles::class => ProbeQueryBinding::of(new ListRolesAction($listings), 'role.list', 1),
            ListGrants::class => ProbeQueryBinding::of(new ListGrantsAction($listings), 'grant.list', 1),
            ListActors::class => ProbeQueryBinding::of(new ListActorsAction($actors), 'actor.list', 1),
            ListNodes::class => ProbeQueryBinding::of(new ListNodesAction(ListingWorld::nodeListing(ListingWorld::ADMIN)), 'node.list', 1),
        ];
    }
}
