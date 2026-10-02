<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\SiteRegistered;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Structure\Domain\Dto\RegisterSiteAggregates;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use Cbox\Cms\Core\Structure\Domain\SiteHandleRef;
use Override;

/**
 * The write action of site.register (PRD 5.8, 5.9, 11.14). resolve() reads, through the
 * SiteDirectory, the site with the command's id and the site that has its handle, both expected
 * absent, so the kernel answers a site or a handle that exists with version_conflict. refusals()
 * refuses, with validation_failed, an empty locale list and a locale named twice. plan() registers
 * the site with its root node and locales.
 *
 * It is exposed on no surface: sites are configuration in git (PRD 11.14), and cms:sites:sync runs
 * the command as the installation operator through the maintenance pipeline, whose
 * MaintenanceAuthorizer names it.
 *
 * @implements WriteAction<RegisterSite, RegisterSiteAggregates>
 * @implements RefusesCommand<RegisterSite, RegisterSiteAggregates>
 */
#[Action(handles: RegisterSite::class)]
#[Internal]
final readonly class RegisterSiteAction implements RefusesCommand, WriteAction
{
    public function __construct(private SiteDirectory $sites) {}

    /**
     * @param  RegisterSite  $command
     */
    #[Override]
    public function resolve(Command $command): RegisterSiteAggregates
    {
        return new RegisterSiteAggregates(
            $command->site,
            $this->sites->find($command->site),
            new SiteHandleRef($command->handle),
            $this->sites->named($command->handle),
        );
    }

    /**
     * @param  RegisterSite  $command
     * @param  RegisterSiteAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $refusals = [];

        if ($command->locales === []) {
            $refusals[] = $this->invalid('locales', 'A site publishes in at least one locale.');
        }

        $seen = [];

        foreach ($command->locales as $index => $locale) {
            if (isset($seen[$locale->value])) {
                $refusals[] = new CatalogError(ErrorCode::ValidationFailed, new FieldPath('locales', $index), sprintf('A site names each locale once, and %s twice.', $locale->value));
            }

            $seen[$locale->value] = true;
        }

        return $refusals;
    }

    /**
     * @param  RegisterSite  $command
     * @param  RegisterSiteAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new SiteRegistered($command->site, $command->handle->value, $command->root, $command->locales));
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
