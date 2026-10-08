<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Actions\ArchiveNodeAction;
use Cbox\Cms\Core\Structure\Actions\CreateNodeAction;
use Cbox\Cms\Core\Structure\Actions\SetNodeRouteAction;
use Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateNode;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Cbox\Cms\Core\Structure\Domain\NodeRouteRef;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakePublicPlacements;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Structure\Fakes\FakeNodeReader;
use Cbox\Cms\Core\Tests\Structure\Fakes\FakeSiteDirectory;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use DateTimeImmutable;
use LogicException;

/**
 * The node actions, node.create, node.archive and node.set_route, in the command pipeline with the
 * fakes of its ports and of the contracts it reads (GUARDRAILS 9): an active editor, a
 * FakeNodeReader, a FakeSiteDirectory and a committer that records what it is asked to commit.
 * Nothing touches a database. The clock stands at NOW.
 *
 * The reader knows the site NORTH on the root ROOT, publishing in da and en, with the section
 * SECTION at version SECTION_VERSION, the archived section ARCHIVED, the mount MOUNT and the
 * section SPARE, which has no route, below the root, and FAR, the root of the site SOUTH, which the
 * editor's regions do not reach. The routes are `/` to ROOT in da and en and `/nyheder` to SECTION
 * in da, and the placement LIVE below SECTION is live without an end.
 */
final class NodeActionWorld
{
    public const string NOW = '2026-03-10T12:00:00.000000+00:00';

    public const string NORTH = '01936f5e-8a2b-7c3d-9e4f-000000000b01';

    public const string SOUTH = '01936f5e-8a2b-7c3d-9e4f-000000000b02';

    public const string ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000b11';

    public const string SECTION = '01936f5e-8a2b-7c3d-9e4f-000000000b12';

    public const string ARCHIVED = '01936f5e-8a2b-7c3d-9e4f-000000000b13';

    public const string MOUNT = '01936f5e-8a2b-7c3d-9e4f-000000000b14';

    public const string SPARE = '01936f5e-8a2b-7c3d-9e4f-000000000b15';

    public const string FAR = '01936f5e-8a2b-7c3d-9e4f-000000000b21';

    public const string NOWHERE = '01936f5e-8a2b-7c3d-9e4f-000000000b31';

    public const string NODE = '01936f5e-8a2b-7c3d-9e4f-000000000b41';

    public const string LIVE = '01936f5e-8a2b-7c3d-9e4f-000000000b51';

    /** The version SECTION is read at. */
    public const int SECTION_VERSION = 2;

    public readonly FakeIdentity $identity;

    public readonly ActorId $editor;

    public readonly FakeNodeReader $nodes;

    public readonly FakeSiteDirectory $sites;

    public readonly FakeClock $clock;

    public FakeChangesetCommitter $committer;

    public readonly StoredNode $root;

    public readonly StoredNode $section;

    public readonly StoredNode $archived;

    public readonly StoredNode $spare;

    private int $keys = 0;

    public function __construct()
    {
        $this->identity = new FakeIdentity;
        $this->editor = $this->identity->addActor(ActorClass::Staff)->id;
        $this->clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $this->committer = new FakeChangesetCommitter;
        $this->nodes = new FakeNodeReader;
        $this->sites = new FakeSiteDirectory;

        $this->root = $this->nodes->withNode(self::node(self::ROOT), kind: NodeKind::Site);
        $this->section = $this->nodes->withNode(self::node(self::SECTION), $this->root, version: self::SECTION_VERSION);
        $this->archived = $this->nodes->withNode(self::node(self::ARCHIVED), $this->root, lifecycle: NodeLifecycle::Archived);
        $this->nodes->withNode(self::node(self::MOUNT), $this->root, kind: NodeKind::Mount);
        $this->spare = $this->nodes->withNode(self::node(self::SPARE), $this->root);
        $far = $this->nodes->withNode(self::node(self::FAR), kind: NodeKind::Site, reachable: false);

        $this->nodes->withRoute(self::site(self::NORTH), new Locale('da'), new RequestPath('/'), $this->root->id);
        $this->nodes->withRoute(self::site(self::NORTH), new Locale('en'), new RequestPath('/'), $this->root->id);
        $this->nodes->withRoute(self::site(self::NORTH), new Locale('da'), new RequestPath('/nyheder'), $this->section->id);
        $this->nodes->withPlacement(PlacementId::fromString(self::LIVE), $this->section);

        $this->sites->add(new StoredSite(self::site(self::NORTH), new SiteHandle('north'), $this->root->id, AggregateVersion::first(), [new Locale('da'), new Locale('en')]));
        $this->sites->add(new StoredSite(self::site(self::SOUTH), new SiteHandle('south'), $far->id, AggregateVersion::first(), [new Locale('da')]));
    }

    public static function node(string $id): NodeId
    {
        return NodeId::fromString($id);
    }

    public static function site(string $id): SiteId
    {
        return SiteId::fromString($id);
    }

    /**
     * The aggregate of a route of NORTH in the locale.
     */
    public static function routeRef(string $route, string $locale = 'da', string $site = self::NORTH): NodeRouteRef
    {
        return new NodeRouteRef(self::site($site), new Locale($locale), new RequestPath($route));
    }

    /**
     * node.create of NODE, or the id given, below the parent.
     */
    public function create(string $parent = self::SECTION, NodeKind $kind = NodeKind::Section, string $node = self::NODE, bool $agent = false): WriteResult
    {
        return $this->run(new CreateNode(self::node($node), self::node($parent), $kind), $agent);
    }

    public function archive(string $node = self::SECTION, int $version = self::SECTION_VERSION, bool $agent = false): WriteResult
    {
        return $this->run(new ArchiveNode(self::node($node), new AggregateVersion($version)), $agent);
    }

    public function setRoute(
        string $node = self::SPARE,
        int $version = 1,
        string $route = '/sport',
        string $site = self::NORTH,
        string $locale = 'da',
        bool $agent = false,
        bool $agentEnvelope = false,
    ): WriteResult {
        $command = new SetNodeRoute(self::node($node), new AggregateVersion($version), self::site($site), new Locale($locale), new RequestPath($route));

        return $this->run($command, $agent, $agentEnvelope);
    }

    public function run(Command $command, bool $agentCredential = false, bool $agentEnvelope = false): WriteResult
    {
        $clock = new FakeClock;
        $keys = new FakeIdempotencyStore($clock)->session();
        $receipts = new FakeReceiptStore($clock)->session();
        $types = new FakeTypeCatalog;

        $pipeline = new CommandPipeline(
            new FakeWriteActions([
                CreateNode::class => $this->binding('node.create', new CreateNodeAction($this->nodes)),
                ArchiveNode::class => $this->binding('node.archive', new ArchiveNodeAction($this->nodes, $this->clock)),
                SetNodeRoute::class => $this->binding('node.set_route', new SetNodeRouteAction($this->nodes, $this->sites)),
            ]),
            $this->identity,
            new FakeCommandAuthorizer,
            $types,
            new FakeFieldValidation(new FakeTypeValidators),
            new FakeRevisionContents,
            new FakePublicPlacements,
            $this->committer,
            $keys,
            $receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(40)),
            new FakeCommandTransaction($keys, $receipts),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
            new AwaitWaitLevel($receipts, new FakePacing, new WaitSettings(0)),
        );

        $envelope = Envelope::external(
            IssuingSurface::Rest,
            $agentEnvelope ? EnvelopeIssuer::Agent : EnvelopeIssuer::Human,
            $this->editor,
            new IdempotencyKey('node-call-'.++$this->keys),
            new CorrelationId('node-correlation'),
        );

        return $pipeline->run(new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($this->editor, [], $agentCredential ? IssuerKind::Agent : IssuerKind::Service, ClassificationAccess::Confidential),
            [],
            ClassificationAccess::Internal,
        )));
    }

    public function commitWith(CommitOutcome $outcome): self
    {
        $this->committer = new FakeChangesetCommitter($outcome);

        return $this;
    }

    /**
     * The one changeset the committer was asked to commit.
     */
    public function committed(): PendingChangeset
    {
        return count($this->committer->pending) === 1
            ? $this->committer->pending[0]
            : throw new LogicException(sprintf('The committer was asked %d times, not once.', count($this->committer->pending)));
    }

    /**
     * @return list<string> each read of the first changeset, as "<aggregate key> <version or ->"
     */
    public function reads(): array
    {
        return array_map(
            static fn (ReadVersion $read): string => $read->aggregate->aggregateKey().' '.($read->version->value ?? '-'),
            $this->committer->pending[0]->reads->reads ?? [],
        );
    }

    /**
     * @return list<string> the codes of a result's errors
     */
    public static function codes(WriteResult $result): array
    {
        return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
    }

    /**
     * @return list<string> the codes with the path of each error
     */
    public static function paths(WriteResult $result): array
    {
        return array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $result->errors);
    }

    /**
     * The binding of a command, version 1, as the registry gives it: it takes any write action.
     */
    private function binding(string $command, object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName($command), 1, $action);
    }
}
