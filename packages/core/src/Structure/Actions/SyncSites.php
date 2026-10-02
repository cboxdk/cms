<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Maintenance\Actions\RunMaintenanceCommand;
use Cbox\Cms\Core\Maintenance\Domain\Dto\MaintenanceCall;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Structure\Domain\Dto\SitesSync;
use Cbox\Cms\Core\Structure\Domain\Dto\SitesSyncReport;
use Cbox\Cms\Core\Structure\Domain\Dto\SiteSync;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use Cbox\Cms\Core\Structure\Domain\SiteSyncOutcome;

/**
 * Materialises the configured sites in the database (PRD 11.14, 5.8, 5.9), the action behind
 * cms:sites:sync: sites and hosts are configuration in git, applied at deploy. For each configured
 * site, in the configured order, it reads the registered site with the handle through the
 * SiteDirectory and:
 *
 * - registers a site the database lacks, with a new root node and the configured locales, through
 *   site.register as the installation operator (RunMaintenanceCommand), one changeset per site;
 * - leaves a site registered with the same locales, in any order, alone, so a second sync writes
 *   nothing;
 * - reports a site registered with other locales as site_locales_drift and writes nothing of it,
 *   because a site's locales change only through the locale commands of a later block, never by
 *   rewriting it from the configuration.
 *
 * A drift or a rejection of one site never stops the others. The unit of work of a registration is
 * `sites:<handle>:<sha256 of the sorted locale tags joined by commas>`, the same for the same
 * configuration. It runs in the maintenance process, from the console, outside any transaction.
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
        return new SitesSyncReport(array_map($this->site(...), $request->sites));
    }

    private function site(ConfiguredSite $configured): SiteSync
    {
        $stored = $this->sites->named($configured->handle);

        if ($stored instanceof StoredSite) {
            return $stored->publishesIn($configured->locales)
                ? new SiteSync($configured->handle, SiteSyncOutcome::Unchanged, $stored->id, $stored->root, null, $configured->locales, $stored->locales)
                : new SiteSync($configured->handle, SiteSyncOutcome::Drifted, $stored->id, $stored->root, null, $configured->locales, $stored->locales, [$this->drift($configured, $stored)]);
        }

        $command = new RegisterSite(new SiteId($this->ids->next()), $configured->handle, new NodeId($this->ids->next()), $configured->locales);
        $result = $this->maintenance->run(new MaintenanceCall($command, self::unitOfWork($configured)));

        if ($result->outcome() === Outcome::Committed) {
            return new SiteSync($configured->handle, SiteSyncOutcome::Registered, $command->site, $command->root, $result->receipt->changesetId, $configured->locales, $configured->locales);
        }

        return new SiteSync($configured->handle, SiteSyncOutcome::Rejected, null, null, null, $configured->locales, [], $result->errors);
    }

    /**
     * The unit of work of a site's registration: the handle and a hash of its configured locales.
     */
    public static function unitOfWork(ConfiguredSite $site): UnitOfWork
    {
        return new UnitOfWork(sprintf('sites:%s:%s', $site->handle->value, hash('sha256', implode(',', StoredSite::tags($site->locales)))));
    }

    private function drift(ConfiguredSite $configured, StoredSite $stored): CatalogError
    {
        return new CatalogError(ErrorCode::SiteLocalesDrift, null, sprintf(
            'The site %s is registered with the locales %s, and cbox-cms.sites configures %s. Nothing of the site was changed. Set its locales in the configuration back to %s, or wait for the locale commands of a later block.',
            $configured->handle->value,
            $this->listed($stored->locales),
            $this->listed($configured->locales),
            $this->listed($stored->locales),
        ));
    }

    /**
     * @param  list<Locale>  $locales
     */
    private function listed(array $locales): string
    {
        return implode(', ', StoredSite::tags($locales));
    }
}
