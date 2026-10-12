<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\SiteLocaleAdded;
use Cbox\Cms\Contracts\Plans\Mutations\SiteLocaleRemoved;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Maintenance\Actions\RunMaintenanceCommand;
use Cbox\Cms\Core\Maintenance\Domain\MaintenanceAuthorizer;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Structure\Actions\AddSiteLocaleAction;
use Cbox\Cms\Core\Structure\Actions\RegisterSiteAction;
use Cbox\Cms\Core\Structure\Actions\RemoveSiteLocaleAction;
use Cbox\Cms\Core\Structure\Actions\SyncSites;
use Cbox\Cms\Core\Structure\Domain\Commands\AddSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Structure\Domain\Commands\RemoveSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeInstallationOperator;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
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
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencySession;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptSession;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use LogicException;

/**
 * site.register, site.add_locale and site.remove_locale through the maintenance pipeline with the
 * fakes of the ports and contracts they read (GUARDRAILS 9): the identity with an active service
 * actor as the installation operator, the MaintenanceAuthorizer, the sites a test adds to the
 * FakeSiteDirectory, their root nodes in the FakeNodeReader, the placements a test says a site has
 * in a locale in the FakeSiteLocaleUse, the committer a test chooses, and the fake idempotency and
 * receipt stores, kept across runs so a rerun of a unit of work replays. Nothing touches a
 * database.
 */
final class SiteCommandFakes
{
    public readonly FakeIdentity $identity;

    public readonly ActorId $operator;

    public readonly FakeSiteDirectory $sites;

    public readonly FakeNodeReader $nodes;

    public readonly FakeSiteLocaleUse $use;

    public readonly FakeClock $clock;

    public readonly IdGenerator $ids;

    public FakeChangesetCommitter $committer;

    private readonly FakeIdempotencySession $keys;

    private readonly FakeReceiptSession $receipts;

    public function __construct()
    {
        $this->identity = new FakeIdentity;
        $this->operator = $this->identity->addActor(ActorClass::Service)->id;
        $this->sites = new FakeSiteDirectory;
        $this->nodes = new FakeNodeReader;
        $this->use = new FakeSiteLocaleUse;
        $this->clock = new FakeClock;
        $this->ids = new FakeIdGenerator(clock: $this->clock);
        $this->keys = new FakeIdempotencyStore($this->clock)->session();
        $this->receipts = new FakeReceiptStore($this->clock)->session();
        $this->committer = new FakeChangesetCommitter(receipts: $this->receipts, ids: $this->ids, events: $this->applied(...));
    }

    /**
     * Keeps the FakeSiteDirectory as a commit leaves a site, as the Postgres directory reads it
     * after one: a locale added or retired, at the version the commit gives the site. The fake
     * commit gives no events.
     *
     * @return list<Event>
     */
    private function applied(Mutation $mutation, AggregateVersion $version): array
    {
        if (! $mutation instanceof SiteLocaleAdded && ! $mutation instanceof SiteLocaleRemoved) {
            return [];
        }

        $this->sites->sites = array_map(function (StoredSite $site) use ($mutation, $version): StoredSite {
            if (! $site->id->equals($mutation->site)) {
                return $site;
            }

            $locales = $mutation instanceof SiteLocaleAdded
                ? [...$site->locales, $mutation->locale]
                : array_values(array_filter($site->locales, static fn (Locale $locale): bool => ! $locale->equals($mutation->locale)));

            return new StoredSite($site->id, $site->handle, $site->root, $version, $locales);
        }, $this->sites->sites);

        return [];
    }

    /**
     * Runs a site command as the operator on an envelope of the maintenance issuer for the unit of
     * work given.
     */
    public function run(RegisterSite|AddSiteLocale|RemoveSiteLocale $command, string $unit = 'sites:test', bool $dryRun = false): WriteResult
    {
        $envelope = Envelope::internal(IssuingSurface::Maintenance, EnvelopeIssuer::System, $this->operator, new UnitOfWork($unit), new CorrelationId('site-command'), dryRun: $dryRun);

        return $this->pipeline()->run(new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($this->operator, [], IssuerKind::Service, ClassificationAccess::Confidential),
            [],
            ClassificationAccess::Public,
        )));
    }

    /**
     * cms:sites:sync's action over these fakes, with the operator installed or not.
     */
    public function sync(bool $installed = true): SyncSites
    {
        return new SyncSites(
            $this->sites,
            new RunMaintenanceCommand(new FakeInstallationOperator($installed ? $this->operator : null), new FakeAccessContexts, $this->ids, $this->pipeline()),
            $this->ids,
        );
    }

    public function commitWith(CommitOutcome $outcome): void
    {
        $this->committer = new FakeChangesetCommitter($outcome, $this->receipts, $this->ids);
    }

    /**
     * @return list<string> each read of the first changeset the committer got, as "<aggregate key> <version or ->"
     */
    public function reads(): array
    {
        return array_map(
            static fn (ReadVersion $read): string => $read->aggregate->aggregateKey().' '.($read->version->value ?? '-'),
            $this->committer->pending[0]->reads->reads ?? [],
        );
    }

    /**
     * The binding of a site command, version 1, as the registry gives it: it takes any write action.
     */
    private function binding(string $command, object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName($command), 1, $action);
    }

    private function pipeline(): CommandPipeline
    {
        $types = new FakeTypeCatalog;

        return new CommandPipeline(
            new FakeWriteActions([
                RegisterSite::class => $this->binding('site.register', new RegisterSiteAction($this->sites)),
                AddSiteLocale::class => $this->binding('site.add_locale', new AddSiteLocaleAction($this->sites)),
                RemoveSiteLocale::class => $this->binding('site.remove_locale', new RemoveSiteLocaleAction($this->sites, $this->use)),
            ]),
            $this->identity,
            new MaintenanceAuthorizer(new FakeInstallationOperator($this->operator)),
            $types,
            new FakeFieldValidation(new FakeTypeValidators),
            new FakeRevisionContents,
            new FakePublicPlacements,
            $this->committer,
            $this->keys,
            $this->receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(40)),
            new FakeCommandTransaction($this->keys, $this->receipts),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
            new AwaitWaitLevel($this->receipts, new FakePacing, new WaitSettings(0)),
        );
    }
}
