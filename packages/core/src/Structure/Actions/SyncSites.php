<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Maintenance\Actions\RunMaintenanceCommand;
use Cbox\Cms\Core\Maintenance\Domain\Dto\MaintenanceCall;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Structure\Domain\Commands\AddSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Structure\Domain\Commands\RemoveSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Dto\LocaleChange;
use Cbox\Cms\Core\Structure\Domain\Dto\SitesSync;
use Cbox\Cms\Core\Structure\Domain\Dto\SitesSyncReport;
use Cbox\Cms\Core\Structure\Domain\Dto\SiteSync;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\LocaleChangeKind;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use Cbox\Cms\Core\Structure\Domain\SiteSyncOutcome;

/**
 * Materialises the configured sites in the database (PRD 11.14, 5.8, 5.9), the action behind
 * cms:sites:sync: sites and hosts are configuration in git, applied at deploy. For each configured
 * site, in the configured order, it reads the registered site with the handle through the
 * SiteDirectory and, as the installation operator through RunMaintenanceCommand:
 *
 * - registers a site the database lacks, with a new root node and the configured locales, through
 *   site.register, one changeset per site;
 * - leaves a site registered with the same locales, in any order, alone, so a second sync writes
 *   nothing;
 * - reconciles a site registered with other locales: site.add_locale for each configured locale
 *   the site lacks, then site.remove_locale for each locale the site publishes in and the
 *   configuration no longer names, one changeset per locale, the additions first so a site that
 *   swaps its one locale never stands without one. Each command expects the site at the version
 *   the directory read it at, moved on by each commit; a locale a placement still uses is
 *   site_locale_in_use, and the other changes of the site are still applied.
 *
 * A rejection of one site or one locale never stops the others. The unit of work of a registration
 * is `sites:<handle>:<sha256 of the sorted locale tags joined by commas>`, and of a locale change
 * `sites:<handle>:<added|removed>:<locale>@<site version>`, the same for the same configuration
 * and state, so a run repeated before the first run's receipts expired replays them. A dry run
 * (SitesSync::$dryRun) runs each command as a dry run, which validates and plans without
 * committing, reports what would happen and writes nothing. It runs in the maintenance process,
 * from the console, outside any transaction.
 */
#[Internal]
final readonly class SyncSites
{
    public function __construct(
        private SiteDirectory $sites,
        private RunMaintenanceCommand $maintenance,
        private IdGenerator $ids,
    ) {}

    public function sync(SitesSync $request): SitesSyncReport
    {
        return new SitesSyncReport(array_map(fn (ConfiguredSite $site): SiteSync => $this->site($site, $request->dryRun), $request->sites), $request->dryRun);
    }

    private function site(ConfiguredSite $configured, bool $dryRun): SiteSync
    {
        $stored = $this->sites->named($configured->handle);

        if ($stored instanceof StoredSite) {
            return $this->reconcile($configured, $stored, $dryRun);
        }

        $command = new RegisterSite(new SiteId($this->ids->next()), $configured->handle, new NodeId($this->ids->next()), $configured->locales);
        $result = $this->run($command, self::unitOfWork($configured), $dryRun);

        if ($this->done($result, $dryRun)) {
            return new SiteSync($configured->handle, SiteSyncOutcome::Registered, $command->site, $command->root, $result->receipt->changesetId, $configured->locales, $configured->locales);
        }

        return new SiteSync($configured->handle, SiteSyncOutcome::Rejected, null, null, null, $configured->locales, [], $result->errors);
    }

    /**
     * The locale changes of a registered site, the additions first.
     */
    private function reconcile(ConfiguredSite $configured, StoredSite $stored, bool $dryRun): SiteSync
    {
        if ($stored->publishesIn($configured->locales)) {
            return new SiteSync($configured->handle, SiteSyncOutcome::Unchanged, $stored->id, $stored->root, null, $configured->locales, $stored->locales);
        }

        $changes = [];
        $current = $stored;

        foreach ($configured->locales as $locale) {
            if (! $current->publishes($locale)) {
                $changes[] = $change = $this->change($configured, new AddSiteLocale($stored->id, $current->version, $locale), LocaleChangeKind::Added, $dryRun);
                $current = $this->after($change, $current);
            }
        }

        foreach ($stored->locales as $locale) {
            if (! $this->configures($configured, $locale)) {
                $changes[] = $change = $this->change($configured, new RemoveSiteLocale($stored->id, $current->version, $locale), LocaleChangeKind::Removed, $dryRun);
                $current = $this->after($change, $current);
            }
        }

        $rejected = array_any($changes, static fn (LocaleChange $change): bool => $change->rejected());

        return new SiteSync($configured->handle, $rejected ? SiteSyncOutcome::Rejected : SiteSyncOutcome::Reconciled, $stored->id, $stored->root, null, $configured->locales, $current->locales, [], $changes);
    }

    /**
     * One locale change through the pipeline.
     */
    private function change(ConfiguredSite $configured, AddSiteLocale|RemoveSiteLocale $command, LocaleChangeKind $kind, bool $dryRun): LocaleChange
    {
        $unit = new UnitOfWork(sprintf('sites:%s:%s:%s@%d', $configured->handle->value, $kind->value, $command->locale->value, $command->version->value));
        $result = $this->run($command, $unit, $dryRun);

        if (! $this->done($result, $dryRun)) {
            return new LocaleChange($command->locale, $kind, null, $result->errors);
        }

        return new LocaleChange($command->locale, $kind, $dryRun ? null : $result->receipt->changesetId);
    }

    /**
     * The site after a committed change, at the next version with the locale added or taken
     * away, as the commit left it; the site as it was after a dry run or a rejection.
     */
    private function after(LocaleChange $change, StoredSite $current): StoredSite
    {
        if (! $change->changeset instanceof ChangesetId) {
            return $current;
        }

        $locales = $change->kind === LocaleChangeKind::Added
            ? [...$current->locales, $change->locale]
            : array_values(array_filter($current->locales, static fn (Locale $locale): bool => ! $locale->equals($change->locale)));

        return new StoredSite($current->id, $current->handle, $current->root, $current->version->next(), $locales);
    }

    private function run(Command $command, UnitOfWork $unit, bool $dryRun): WriteResult
    {
        return $this->maintenance->run(new MaintenanceCall($command, $unit, $dryRun));
    }

    /**
     * Whether the call did what the sync asked: committed, or validated on a dry run.
     */
    private function done(WriteResult $result, bool $dryRun): bool
    {
        return $result->outcome() === ($dryRun ? Outcome::DryRun : Outcome::Committed);
    }

    private function configures(ConfiguredSite $site, Locale $locale): bool
    {
        return array_any($site->locales, static fn (Locale $configured): bool => $configured->equals($locale));
    }

    /**
     * The unit of work of a site's registration: the handle and a hash of its configured locales.
     */
    public static function unitOfWork(ConfiguredSite $site): UnitOfWork
    {
        return new UnitOfWork(sprintf('sites:%s:%s', $site->handle->value, hash('sha256', implode(',', StoredSite::tags($site->locales)))));
    }
}
