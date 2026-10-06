<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Access\Actions\CreateRoleAction;
use Cbox\Cms\Core\Access\Domain\Commands\CreateRole;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\PermissionRule;
use Cbox\Cms\Core\Access\Domain\Queries\ListGrants;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelCommandCodecs;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelQueryCodecs;
use Cbox\Cms\Core\Identity\Actions\ListActorsAction;
use Cbox\Cms\Core\Identity\Actions\RegisterActorAction;
use Cbox\Cms\Core\Identity\Actions\WhoAmIAction;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Identity\Domain\Queries\ListActors;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Maintenance\Actions\GrantBootstrapRoleAction;
use Cbox\Cms\Core\Maintenance\Domain\Commands\GrantBootstrapRole;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Publishing\Actions\PublishEntryAction;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Registry\Actions\ListActionsAction;
use Cbox\Cms\Core\Registry\Adapter\CodecContractSummaries;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use Cbox\Cms\Core\Registry\Domain\PointStability;
use Cbox\Cms\Core\Registry\Domain\Queries\ListActions;
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessResolver;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeOwnHeldGrants;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakePanelActivation;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Panel\Shell\Domain\Dto\ShellNavV1;
use Cbox\Cms\Panel\Shell\Domain\Dto\ShellPageV1;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateInterval;

/**
 * action.list over fakes (GUARDRAILS 9, PRD 13.2, 13.4): a compiled registry, the one given or
 * fixture(), in a FakeRegistryCache; the activation state of a FakePanelActivation; the actor's
 * grants in FakePermissions, read by FakeOwnHeldGrants under the context the FakeAccessResolver
 * set for the read, so each credential reads its own; the titles and descriptions of the kernel's
 * contracts from their generated codecs; and the real QueryPipeline over them, with the identity
 * given, or FakeIdentity, as the credential verifier. A reader is a service actor of the identity
 * with a Bearer credential, whose regions reach the root with the classification access given,
 * holding a role on the root whose permissions are those a test gives.
 *
 * The fixture registry holds, by name: access.bootstrap on no surface; action.list, actor.list and
 * actor.me on REST and Inertia; actor.register on the CLI alone; entry.publish on every surface;
 * path.resolve, a public query, on no surface; probe.add on REST and Inertia, a write whose
 * contract no codec reads; and role.create on REST, Inertia and the CLI. Its panel registry has
 * shell.nav@1 with the core's entry of the who-am-I page (cms.account-me, requiring nothing), an
 * entry of a page of the panel's own requiring grant.list (cms.grants), and the entries of three
 * pages of the addon tally on shell.page@1: tally.board, which requires tally.board; tally.gone,
 * which the installation disabled; and tally.secret, which requires tally.secret.
 */
final class ActionListWorld
{
    public const string ROOT = '0192a0c0-0000-7000-8000-000000000d01';

    public const string PATH = 'root';

    public const string PACKAGE = 'cboxdk/cms';

    public const string ADDON_PACKAGE = 'acme/tally';

    public readonly FakeClock $clock;

    public readonly FakeIdentity $identity;

    public readonly CredentialVerifier $credentials;

    public readonly FakeAccessResolver $access;

    public readonly FakePermissions $permissions;

    public readonly FakeRegistryCache $cache;

    public readonly FakePanelActivation $activation;

    public readonly CompiledRegistry $registry;

    private int $roles = 0;

    public function __construct(?CompiledRegistry $registry = null, ?CredentialVerifier $credentials = null, ?FakeClock $clock = null, ?FakeIdentity $identity = null)
    {
        $this->clock = $clock ?? new FakeClock;
        $this->identity = $identity ?? new FakeIdentity($this->clock);
        $this->credentials = $credentials ?? $this->identity;
        $this->access = new FakeAccessResolver;
        $this->permissions = new FakePermissions([self::ROOT => new NodePath(self::PATH)]);
        $this->registry = $registry ?? self::fixture();
        $this->cache = new FakeRegistryCache;
        $this->cache->write($this->registry);
        $this->activation = new FakePanelActivation;
    }

    /**
     * Gives the actor regions over the root with the classification access given and, with
     * permissions, a role on the root whose permissions are the names given, allowing or denying,
     * in the locales given or every locale.
     *
     * @param  list<string>  $permissions
     * @param  list<string>|null  $locales
     */
    public function grant(ActorId $actor, array $permissions, GrantEffect $effect = GrantEffect::Allow, ?array $locales = null, ClassificationAccess $access = ClassificationAccess::Internal): void
    {
        $this->access->grant($actor, [new AccessRegion(new NodePath(self::PATH))], $access);

        if ($permissions === []) {
            return;
        }

        $this->permissions->grant($actor, new Grant(
            RoleId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 700 + $this->roles++)),
            $access,
            new NodePath(self::PATH),
            $effect,
            $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales),
        ), array_map(static fn (string $name): CommandName => new CommandName($name), $permissions));
    }

    /**
     * A new active service actor with the grant given, and its Bearer credential.
     *
     * @param  list<string>  $permissions
     */
    public function reader(array $permissions, GrantEffect $effect = GrantEffect::Allow): TransportCredential
    {
        $actor = $this->identity->addActor(ActorClass::Service)->id;
        $this->grant($actor, $permissions, $effect);

        return $this->credential($actor);
    }

    /**
     * A Bearer credential of the actor, issued by the identity.
     */
    public function credential(ActorId $actor): TransportCredential
    {
        return $this->identity->issue(new ServiceCredentialSpec($actor, IssuerKind::Service, ClassificationAccess::Sensitive, $this->clock->now()->add(new DateInterval('P1D'))));
    }

    /**
     * The action over the world's fakes.
     */
    public function action(): ListActionsAction
    {
        return new ListActionsAction(
            $this->cache,
            $this->activation,
            FakeOwnHeldGrants::following($this->permissions, $this->access),
            new CodecContractSummaries(new CommandCodecs(...KernelCommandCodecs::all()), new QueryCodecs(...KernelQueryCodecs::all())),
            new PermissionRule,
        );
    }

    /**
     * Runs the query through the pipeline, as the credential's actor or as the anonymous principal.
     */
    public function read(Query $query, ?TransportCredential $credential, ?Surface $surface = null): QueryResult
    {
        return $this->pipeline()->run(new QueryCall($query, $credential, $surface));
    }

    /**
     * The query pipeline over the world's fakes, which a surface test binds in the container, with
     * the authorizer deciding from the world's grants as the kernel's does unless a test gives one,
     * and the bindings of other queries a test's pages read beside action.list.
     *
     * @param  array<class-string<Query>, QueryBinding>  $more
     */
    public function pipeline(?FakeQueryAuthorizer $authorizer = null, array $more = []): QueryPipeline
    {
        return new QueryPipeline(
            new FakeQueryActions([ListActions::class => ProbeQueryBinding::of($this->action(), 'action.list', 1), ...$more]),
            $this->credentials,
            $this->access,
            $authorizer ?? FakeQueryAuthorizer::granting($this->permissions),
            new QuerySettings(new QueryCost(200), new QueryCost(1000)),
            new ReadableFields(new FakeTypeCatalog),
            new FakeReadAudit,
            new FakeQueryTransaction(sessions: $this->access),
            new PipelineTelemetry(new FakeTelemetry, $this->clock, new FakeStopwatch),
        );
    }

    /**
     * The registry of the world, as cms:build would compile it.
     */
    public static function fixture(): CompiledRegistry
    {
        $everywhere = [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli];
        $panel = [Surface::Rest, Surface::Inertia];

        return new CompiledRegistry(
            commands: [],
            hooks: [],
            actions: [
                new ActionEntry(GrantBootstrapRoleAction::class, self::PACKAGE, ActionKind::Write, new CommandName('access.bootstrap'), 1, GrantBootstrapRole::class, []),
                new ActionEntry(ListActionsAction::class, self::PACKAGE, ActionKind::Query, new CommandName('action.list'), 1, ListActions::class, $panel),
                new ActionEntry(ListActorsAction::class, self::PACKAGE, ActionKind::Query, new CommandName('actor.list'), 1, ListActors::class, $panel),
                new ActionEntry(WhoAmIAction::class, self::PACKAGE, ActionKind::Query, new CommandName('actor.me'), 1, WhoAmI::class, $panel),
                new ActionEntry(RegisterActorAction::class, self::PACKAGE, ActionKind::Write, new CommandName('actor.register'), 1, RegisterActor::class, [Surface::Cli]),
                new ActionEntry(PublishEntryAction::class, self::PACKAGE, ActionKind::Write, new CommandName('entry.publish'), 1, PublishEntry::class, $everywhere),
                new ActionEntry(ResolvePathAction::class, self::PACKAGE, ActionKind::Query, new CommandName('path.resolve'), 1, ResolvePath::class, []),
                new ActionEntry('Acme\Probe\Actions\ProbeAddAction', 'acme/probe', ActionKind::Write, new CommandName('probe.add'), 1, 'Acme\Probe\Domain\Commands\ProbeAdd', $panel),
                new ActionEntry(CreateRoleAction::class, self::PACKAGE, ActionKind::Write, new CommandName('role.create'), 1, CreateRole::class, [Surface::Rest, Surface::Inertia, Surface::Cli]),
            ],
            panel: [
                new PanelPointEntry(
                    new PanelPoint(name: 'shell.nav', version: 1, kind: PointKind::Nav, page: 'shell', since: '1.0', label: 'panel.points.shell_nav', multiplicity: Multiplicity::Many),
                    ShellNavV1::class,
                    self::PACKAGE,
                    PointStability::Experimental,
                    [
                        new PanelFill(new NavContribution(new ContributionId('cms.account-me'), 'shell.nav@1', 'panel.nav.account_me', 'account.me', null, 100), self::PACKAGE, 100),
                        new PanelFill(new NavContribution(new ContributionId('cms.grants'), 'shell.nav@1', 'panel.nav.grants', 'access.grants', 'shield', 200, new Scope(requires: new CommandName('grant.list'))), self::PACKAGE, 200),
                        new PanelFill(new NavContribution(new ContributionId('tally.board-link'), 'shell.nav@1', 'tally.nav.board', 'tally.board', 'inbox'), self::ADDON_PACKAGE, 1000),
                        new PanelFill(new NavContribution(new ContributionId('tally.gone-link'), 'shell.nav@1', 'tally.nav.gone', 'tally.gone'), self::ADDON_PACKAGE, 1000),
                        new PanelFill(new NavContribution(new ContributionId('tally.secret-link'), 'shell.nav@1', 'tally.nav.secret', 'tally.secret'), self::ADDON_PACKAGE, 1000),
                    ],
                ),
                new PanelPointEntry(
                    new PanelPoint(name: 'shell.page', version: 1, kind: PointKind::Page, page: 'shell', since: '1.0', label: 'panel.points.shell_page', multiplicity: Multiplicity::Many),
                    ShellPageV1::class,
                    self::PACKAGE,
                    PointStability::Experimental,
                    [
                        new PanelFill(new PageContribution(new ContributionId('tally.board'), 'shell.page@1', 'board', ListGrants::class, scope: new Scope(requires: new CommandName('tally.board'))), self::ADDON_PACKAGE, 1000),
                        new PanelFill(new PageContribution(new ContributionId('tally.gone'), 'shell.page@1', 'gone', ListGrants::class), self::ADDON_PACKAGE, 1000, enabled: false),
                        new PanelFill(new PageContribution(new ContributionId('tally.secret'), 'shell.page@1', 'secret', ListGrants::class, scope: new Scope(requires: new CommandName('tally.secret'))), self::ADDON_PACKAGE, 1000),
                    ],
                ),
            ],
        );
    }
}
