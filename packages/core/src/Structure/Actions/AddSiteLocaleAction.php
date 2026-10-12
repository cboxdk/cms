<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\SiteLocaleAdded;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Structure\Domain\Commands\AddSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Dto\SiteLocaleAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use LogicException;
use Override;

/**
 * The write action of site.add_locale (PRD 5.9, 11.14), exposed on every surface. resolve() reads
 * the site with its locales and its root node through the SiteDirectory; refusals() refuses what
 * that read rules out; plan() adds the locale.
 *
 * It is decided on the site's root node (PRD 5.10): the aggregates' authorization scope is the root
 * in the locale, so the kernel's authorizer allows the command only through a role that reaches
 * the root there, and the row level security of `sites` and `site_locales` takes the rows only for
 * an actor that reaches the root or in the transaction of the command's own changeset. A locale the
 * site publishes in already is validation_failed; a site that does not exist is version_conflict,
 * because the command expects the site at the version the caller read. cms:sites:sync runs it as
 * the installation operator through the maintenance pipeline, whose MaintenanceAuthorizer names it.
 *
 * @implements WriteAction<AddSiteLocale, SiteLocaleAggregates>
 * @implements RefusesCommand<AddSiteLocale, SiteLocaleAggregates>
 */
#[Action(handles: AddSiteLocale::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class AddSiteLocaleAction implements RefusesCommand, WriteAction
{
    public function __construct(private SiteDirectory $sites) {}

    /**
     * @param  AddSiteLocale  $command
     */
    #[Override]
    public function resolve(Command $command): SiteLocaleAggregates
    {
        return new SiteLocaleAggregates($command->site, $this->sites->find($command->site), $command->locale);
    }

    /**
     * @param  AddSiteLocale  $command
     * @param  SiteLocaleAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $site = $aggregates->storedSite;

        if (! $site instanceof StoredSite) {
            return [new CatalogError(ErrorCode::ValidationFailed, new FieldPath('site'), sprintf('No site %s exists.', $command->site->toString()))];
        }

        if ($site->publishes($command->locale)) {
            return [new CatalogError(ErrorCode::ValidationFailed, new FieldPath('locale'), sprintf('The site %s publishes in %s already.', $command->site->toString(), $command->locale->value))];
        }

        return [];
    }

    /**
     * @param  AddSiteLocale  $command
     * @param  SiteLocaleAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $site = $aggregates->storedSite ?? throw new LogicException(sprintf('site.add_locale plans a locale of %s, which its refusals rule out when the site does not exist.', $command->site->toString()));

        return new Plan(new SiteLocaleAdded($command->site, $site->root, $command->locale));
    }
}
