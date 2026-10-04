<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Access\Actions\ListGrantsAction;
use Cbox\Cms\Core\Access\Actions\ListRolesAction;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\Queries\ListGrants;
use Cbox\Cms\Core\Access\Domain\Queries\ListRoles;
use Cbox\Cms\Core\Identity\Actions\ListActorsAction;
use Cbox\Cms\Core\Identity\Actions\WhoAmIAction;
use Cbox\Cms\Core\Identity\Domain\Queries\ListActors;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Structure\Actions\ListNodesAction;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessResolver;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateInterval;

/**
 * The access queries run through the real QueryPipeline with fakes for its ports (GUARDRAILS 9):
 * the identity, the access resolver, the read audit and the read transaction, the query
 * authorizer deciding from FakePermissions as the Postgres one does, and the five actions over the
 * listing fakes of ListingWorld read as ADMIN reads them, every profile included, actor.me giving
 * ADMIN's own self. A reader is an
 * service actor of the identity, with a credential whose ceiling is sensitive, sent as a Bearer
 * token, whose regions reach the
 * whole tree and whose classification access a test gives, holding a role whose permissions are
 * those a test gives.
 */
final class ListingActionWorld
{
    public readonly FakeIdentity $identity;

    public readonly FakeAccessResolver $access;

    public readonly FakePermissions $permissions;

    public readonly FakeQueryAuthorizer $authorizer;

    private readonly FakeClock $clock;

    private int $roles = 0;

    public function __construct()
    {
        $this->clock = new FakeClock;
        $this->identity = new FakeIdentity($this->clock);
        $this->access = new FakeAccessResolver;
        $this->permissions = new FakePermissions([ListingWorld::ROOT => new NodePath($this->label(ListingWorld::ROOT))]);
        $this->authorizer = FakeQueryAuthorizer::granting($this->permissions);
    }

    /**
     * A new active service actor whose regions reach the whole tree with the classification access
     * given, holding a role on the root whose permissions are the names given, and its credential.
     *
     * @param  list<string>  $permissions
     */
    public function reader(array $permissions, ClassificationAccess $access): TransportCredential
    {
        $actor = $this->identity->addActor(ActorClass::Service)->id;
        $this->access->grant($actor, [new AccessRegion(new NodePath($this->label(ListingWorld::ROOT)))], $access);

        if ($permissions !== []) {
            $this->permissions->grant($actor, new Grant(
                RoleId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 900 + $this->roles++)),
                $access,
                new NodePath($this->label(ListingWorld::ROOT)),
                GrantEffect::Allow,
            ), array_map(static fn (string $name): CommandName => new CommandName($name), $permissions));
        }

        return $this->identity->issue(new ServiceCredentialSpec($actor, IssuerKind::Service, ClassificationAccess::Sensitive, $this->clock->now()->add(new DateInterval('P1D'))));
    }

    /**
     * Runs the query through the pipeline, as the credential's actor or as the anonymous principal.
     */
    public function read(Query $query, ?TransportCredential $credential): QueryResult
    {
        return $this->pipeline()->run(new QueryCall($query, $credential));
    }

    /**
     * The query pipeline over the world's fakes, which a surface test binds in the container.
     */
    public function pipeline(): QueryPipeline
    {
        return new QueryPipeline(
            new FakeQueryActions([
                ListRoles::class => ProbeQueryBinding::of(new ListRolesAction(ListingWorld::accessListings(ListingWorld::ADMIN)), 'role.list', 1),
                ListGrants::class => ProbeQueryBinding::of(new ListGrantsAction(ListingWorld::accessListings(ListingWorld::ADMIN)), 'grant.list', 1),
                ListActors::class => ProbeQueryBinding::of(new ListActorsAction(ListingWorld::actorListing(ListingWorld::ADMIN)), 'actor.list', 1),
                ListNodes::class => ProbeQueryBinding::of(new ListNodesAction(ListingWorld::nodeListing(ListingWorld::ADMIN)), 'node.list', 1),
                WhoAmI::class => ProbeQueryBinding::of(new WhoAmIAction(ListingWorld::ownActorReader(ListingWorld::ADMIN)), 'actor.me', 1),
            ]),
            $this->identity,
            $this->access,
            $this->authorizer,
            new QuerySettings(new QueryCost(200), new QueryCost(1000)),
            new ReadableFields(new FakeTypeCatalog),
            new FakeReadAudit,
            new FakeQueryTransaction(sessions: $this->access),
            new PipelineTelemetry(new FakeTelemetry, $this->clock, new FakeStopwatch),
        );
    }

    private function label(string $node): string
    {
        return str_replace('-', '', $node);
    }
}
