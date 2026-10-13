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
use Cbox\Cms\Contracts\Ids\EntryId;
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
use Cbox\Cms\Core\Structure\Actions\AddSiteLocaleAction;
use Cbox\Cms\Core\Structure\Actions\ArchiveNodeAction;
use Cbox\Cms\Core\Structure\Actions\ChangeNodeRouteAction;
use Cbox\Cms\Core\Structure\Actions\CreateMountAction;
use Cbox\Cms\Core\Structure\Actions\CreateNodeAction;
use Cbox\Cms\Core\Structure\Actions\MoveNodeAction;
use Cbox\Cms\Core\Structure\Actions\RemoveSiteLocaleAction;
use Cbox\Cms\Core\Structure\Actions\SetMountOverrideAction;
use Cbox\Cms\Core\Structure\Actions\SetNodeLabelAction;
use Cbox\Cms\Core\Structure\Actions\SetNodeRouteAction;
use Cbox\Cms\Core\Structure\Domain\Commands\AddSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\ChangeNodeRoute;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateMount;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateNode;
use Cbox\Cms\Core\Structure\Domain\Commands\MoveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\RemoveSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Commands\SetMountOverride;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeLabel;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\NodeLabel;
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
use Cbox\Cms\Core\Tests\Structure\Fakes\FakeSiteLocaleUse;
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
 * The structure actions, node.create, node.archive, node.set_route, node.move, node.set_label,
 * mount.create, mount.set_override, site.add_locale and site.remove_locale, in the command
 * pipeline with the fakes of its ports and of the contracts it reads (GUARDRAILS 9): an active
 * editor, a FakeNodeReader, a FakeSiteDirectory, a FakeSiteLocaleUse and a committer that records
 * what it is asked to commit. Nothing touches a database. The clock stands at NOW.
 *
 * The reader knows the site NORTH on the root ROOT, publishing in da and en, with the section
 * SECTION at version SECTION_VERSION, the archived section ARCHIVED, the mount MOUNT of SECTION
 * and the section SPARE, which has no route, below the root, the page CHILD below SECTION, FAR,
 * the root of the site SOUTH, which the editor's regions do not reach, with the section FAR_CHILD
 * and the mount FAR_MOUNT of SECTION below it, and WEST_ROOT, the root of the site WEST, which
 * they do reach. The routes are `/` to ROOT in da and en and `/nyheder` to SECTION in da, the
 * placement LIVE of the entry ENTRY below SECTION is live without an end, and so is SPARE_LIVE
 * below SPARE, so NORTH has placements in da and none in en; NO_ENTRY is the id of no entry.
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

    public const string FAR_CHILD = '01936f5e-8a2b-7c3d-9e4f-000000000b22';

    public const string FAR_MOUNT = '01936f5e-8a2b-7c3d-9e4f-000000000b24';

    public const string ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000000b61';

    public const string NO_ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000000b62';

    public const string WEST = '01936f5e-8a2b-7c3d-9e4f-000000000b03';

    public const string WEST_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000b23';

    public const string CHILD = '01936f5e-8a2b-7c3d-9e4f-000000000b16';

    public const string SPARE_LIVE = '01936f5e-8a2b-7c3d-9e4f-000000000b52';

    public const string NOWHERE = '01936f5e-8a2b-7c3d-9e4f-000000000b31';

    public const string NODE = '01936f5e-8a2b-7c3d-9e4f-000000000b41';

    public const string LIVE = '01936f5e-8a2b-7c3d-9e4f-000000000b51';

    /** The version SECTION is read at. */
    public const int SECTION_VERSION = 2;

    public readonly FakeIdentity $identity;

    public readonly ActorId $editor;

    public readonly FakeNodeReader $nodes;

    public readonly FakeSiteDirectory $sites;

    public readonly FakeSiteLocaleUse $use;

    public readonly FakeClock $clock;

    public FakeChangesetCommitter $committer;

    public readonly StoredNode $root;

    public readonly StoredNode $section;

    public readonly StoredNode $archived;

    public readonly StoredNode $spare;

    public readonly StoredNode $child;

    public readonly StoredNode $westRoot;

    private int $keys = 0;

    public function __construct()
    {
        $this->identity = new FakeIdentity;
        $this->editor = $this->identity->addActor(ActorClass::Staff)->id;
        $this->clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $this->committer = new FakeChangesetCommitter;
        $this->nodes = new FakeNodeReader;
        $this->sites = new FakeSiteDirectory;
        $this->use = new FakeSiteLocaleUse()->withPlacements(self::site(self::NORTH), new Locale('da'), 1);

        $this->root = $this->nodes->withNode(self::node(self::ROOT), kind: NodeKind::Site);
        $this->section = $this->nodes->withNode(self::node(self::SECTION), $this->root, version: self::SECTION_VERSION);
        $this->archived = $this->nodes->withNode(self::node(self::ARCHIVED), $this->root, lifecycle: NodeLifecycle::Archived);
        $this->nodes->withNode(self::node(self::MOUNT), $this->root, kind: NodeKind::Mount, mountSource: $this->section->id);
        $this->spare = $this->nodes->withNode(self::node(self::SPARE), $this->root);
        $this->child = $this->nodes->withNode(self::node(self::CHILD), $this->section, NodeKind::Page);
        $far = $this->nodes->withNode(self::node(self::FAR), kind: NodeKind::Site, reachable: false);
        $this->nodes->withNode(self::node(self::FAR_CHILD), $far, reachable: false);
        $this->nodes->withNode(self::node(self::FAR_MOUNT), $far, kind: NodeKind::Mount, reachable: false, mountSource: $this->section->id);
        $this->westRoot = $this->nodes->withNode(self::node(self::WEST_ROOT), kind: NodeKind::Site);

        $this->nodes->withRoute(self::site(self::NORTH), new Locale('da'), new RequestPath('/'), $this->root->id);
        $this->nodes->withRoute(self::site(self::NORTH), new Locale('en'), new RequestPath('/'), $this->root->id);
        $this->nodes->withRoute(self::site(self::NORTH), new Locale('da'), new RequestPath('/nyheder'), $this->section->id);
        $this->nodes->withPlacement(PlacementId::fromString(self::LIVE), $this->section, entry: EntryId::fromString(self::ENTRY));
        $this->nodes->withPlacement(PlacementId::fromString(self::SPARE_LIVE), $this->spare);

        $this->sites->add(new StoredSite(self::site(self::NORTH), new SiteHandle('north'), $this->root->id, AggregateVersion::first(), [new Locale('da'), new Locale('en')]));
        $this->sites->add(new StoredSite(self::site(self::SOUTH), new SiteHandle('south'), $far->id, AggregateVersion::first(), [new Locale('da')]));
        $this->sites->add(new StoredSite(self::site(self::WEST), new SiteHandle('west'), $this->westRoot->id, AggregateVersion::first(), [new Locale('da')]));
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

    /**
     * node.set_route version 2 of the node, read at the version given.
     */
    public function changeRoute(
        string $node = self::SECTION,
        int $version = self::SECTION_VERSION,
        string $route = '/aktuelt',
        string $site = self::NORTH,
        string $locale = 'da',
        bool $agent = false,
        bool $agentEnvelope = false,
    ): WriteResult {
        $command = new ChangeNodeRoute(self::node($node), new AggregateVersion($version), self::site($site), new Locale($locale), new RequestPath($route));

        return $this->run($command, $agent, $agentEnvelope);
    }

    /**
     * node.move of the node, read at the version given, below the parent.
     */
    public function move(string $node = self::SPARE, int $version = 1, string $parent = self::SECTION, bool $agent = false, bool $agentEnvelope = false): WriteResult
    {
        return $this->run(new MoveNode(self::node($node), new AggregateVersion($version), self::node($parent)), $agent, $agentEnvelope);
    }

    /**
     * mount.create of NODE, or the id given, below the parent, showing the source.
     */
    public function createMount(string $parent = self::SECTION, string $source = self::SPARE, string $node = self::NODE, bool $agent = false): WriteResult
    {
        return $this->run(new CreateMount(self::node($node), self::node($parent), self::node($source)), $agent);
    }

    /**
     * mount.set_override of the mount, read at the version given, for the entry.
     */
    public function setOverride(string $mount = self::MOUNT, int $version = 1, string $entry = self::ENTRY, bool $hidden = true, bool $agent = false, bool $agentEnvelope = false): WriteResult
    {
        return $this->run(new SetMountOverride(self::node($mount), new AggregateVersion($version), EntryId::fromString($entry), $hidden), $agent, $agentEnvelope);
    }

    /**
     * node.set_label of the node, read at the version given, in the locale.
     */
    public function setLabel(string $node = self::SECTION, int $version = self::SECTION_VERSION, string $locale = 'da', string $label = 'Nyheder', bool $agent = false): WriteResult
    {
        return $this->run(new SetNodeLabel(self::node($node), new AggregateVersion($version), new Locale($locale), new NodeLabel($label)), $agent);
    }

    public function addLocale(string $locale = 'de', int $version = 1, string $site = self::NORTH): WriteResult
    {
        return $this->run(new AddSiteLocale(self::site($site), new AggregateVersion($version), new Locale($locale)));
    }

    public function removeLocale(string $locale = 'en', int $version = 1, string $site = self::NORTH): WriteResult
    {
        return $this->run(new RemoveSiteLocale(self::site($site), new AggregateVersion($version), new Locale($locale)));
    }

    public function run(Command $command, bool $agentCredential = false, bool $agentEnvelope = false, bool $dryRun = false): WriteResult
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
                ChangeNodeRoute::class => $this->binding('node.set_route', new ChangeNodeRouteAction($this->nodes, $this->sites), version: 2),
                MoveNode::class => $this->binding('node.move', new MoveNodeAction($this->nodes, $this->clock)),
                SetNodeLabel::class => $this->binding('node.set_label', new SetNodeLabelAction($this->nodes)),
                AddSiteLocale::class => $this->binding('site.add_locale', new AddSiteLocaleAction($this->sites)),
                RemoveSiteLocale::class => $this->binding('site.remove_locale', new RemoveSiteLocaleAction($this->sites, $this->use)),
                CreateMount::class => $this->binding('mount.create', new CreateMountAction($this->nodes)),
                SetMountOverride::class => $this->binding('mount.set_override', new SetMountOverrideAction($this->nodes, $this->clock)),
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
            dryRun: $dryRun,
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
    private function binding(string $command, object $action, int $version = 1): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName($command), $version, $action);
    }
}
