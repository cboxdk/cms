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
use Cbox\Cms\Contracts\Plans\Mutations\SiteLocaleRemoved;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Structure\Domain\Commands\RemoveSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Dto\SiteLocaleAggregates;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use Cbox\Cms\Core\Structure\Domain\SiteLocaleUse;
use Cbox\Cms\Core\Structure\Domain\SiteLocaleUseRef;
use LogicException;
use Override;

/**
 * The write action of site.remove_locale (PRD 5.9, 11.14), exposed on every surface. resolve()
 * reads the site with its locales and its root node through the SiteDirectory and the site's use
 * of the locale through SiteLocaleUse, past the actor's regions; refusals() refuses what those reads
 * rule out; plan() retires the locale.
 *
 * It is decided on the site's root node (PRD 5.10): the aggregates' authorization scope is the root
 * in the locale, so the kernel's authorizer allows the command only through a role that reaches
 * the root there, and the row level security of `sites` and `site_locales` takes the rows only for
 * an actor that reaches the root or in the transaction of the command's own changeset. A locale a
 * placement still uses anywhere on the site is site_locale_in_use, with the count and the next
 * step; the use is an aggregate the plan reads as absent, so a placement created in the locale
 * between the read and the commit is version_conflict, never an orphan. A locale the site does not
 * publish in, and the site's last locale, are validation_failed; a site that does not exist is
 * version_conflict, because the command expects the site at the version the caller read.
 * cms:sites:sync runs it as the installation operator through the maintenance pipeline, whose
 * MaintenanceAuthorizer names it.
 *
 * @implements WriteAction<RemoveSiteLocale, SiteLocaleAggregates>
 * @implements RefusesCommand<RemoveSiteLocale, SiteLocaleAggregates>
 */
#[Action(handles: RemoveSiteLocale::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class RemoveSiteLocaleAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private SiteDirectory $sites,
        private SiteLocaleUse $use,
    ) {}

    /**
     * @param  RemoveSiteLocale  $command
     */
    #[Override]
    public function resolve(Command $command): SiteLocaleAggregates
    {
        $site = $this->sites->find($command->site);

        if (! $site instanceof StoredSite) {
            return new SiteLocaleAggregates($command->site, null, $command->locale);
        }

        return new SiteLocaleAggregates(
            $command->site,
            $site,
            $command->locale,
            new SiteLocaleUseRef($command->site, $command->locale),
            $this->use->placements($command->site, $command->locale),
        );
    }

    /**
     * @param  RemoveSiteLocale  $command
     * @param  SiteLocaleAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $site = $aggregates->storedSite;

        if (! $site instanceof StoredSite) {
            return [$this->invalid('site', sprintf('No site %s exists.', $command->site->toString()))];
        }

        if (! $site->publishes($command->locale)) {
            return [$this->invalid('locale', sprintf('The site %s does not publish in %s.', $command->site->toString(), $command->locale->value))];
        }

        if (count($site->locales) === 1) {
            return [$this->invalid('locale', sprintf('The locale %s is the last one of the site %s, and a site publishes in at least one locale; add another locale first.', $command->locale->value, $command->site->toString()))];
        }

        if ($aggregates->placements > 0) {
            return [new CatalogError(ErrorCode::SiteLocaleInUse, new FieldPath('locale'), sprintf(
                'The site %s still has %d placement%s in %s that %s not withdrawn. Withdraw %s, or move the content to another locale, and run the command again.',
                $command->site->toString(),
                $aggregates->placements,
                $aggregates->placements === 1 ? '' : 's',
                $command->locale->value,
                $aggregates->placements === 1 ? 'is' : 'are',
                $aggregates->placements === 1 ? 'it' : 'them',
            ))];
        }

        return [];
    }

    /**
     * @param  RemoveSiteLocale  $command
     * @param  SiteLocaleAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $site = $aggregates->storedSite ?? throw new LogicException(sprintf('site.remove_locale plans a locale of %s, which its refusals rule out when the site does not exist.', $command->site->toString()));

        return new Plan(new SiteLocaleRemoved($command->site, $site->root, $command->locale));
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
