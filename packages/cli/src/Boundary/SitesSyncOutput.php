<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Structure\Domain\Dto\SitesSyncReport;
use Cbox\Cms\Core\Structure\Domain\Dto\SiteSync;
use Cbox\Cms\Core\Structure\Domain\SiteSyncOutcome;
use InvalidArgumentException;

/**
 * What cms:sites:sync answers (PRD 11.14, GUARDRAILS 2.1), with exit codes from the error catalog:
 * one line per configured site on standard output, each error of a site that did not sync on
 * standard error with its catalog code, and the exit code 0 when every site synced, or the exit code
 * of the first error of the first site that did not; ExitCode::Config for a setting that cannot be
 * read.
 */
#[Internal]
final readonly class SitesSyncOutput
{
    public static function of(SitesSyncReport $report): CliAnswer
    {
        $output = [];
        $errors = [];

        foreach ($report->sites as $site) {
            $output[] = self::line($site);

            foreach ($site->errors as $error) {
                $errors[] = sprintf('[%s] %s: %s', $error->code->value, $site->handle->value, $error->message);
            }
        }

        if ($report->sites === []) {
            $output[] = 'cbox-cms.sites configures no site. Nothing changed.';
        }

        $first = $report->firstError();

        return new CliAnswer($first instanceof CatalogError ? $first->code->entry()->exit : ExitCode::Ok, $output, $errors);
    }

    public static function invalidConfiguration(InvalidArgumentException $invalid): CliAnswer
    {
        return new CliAnswer(ExitCode::Config, [], [$invalid->getMessage().' Nothing changed.']);
    }

    private static function line(SiteSync $site): string
    {
        $handle = $site->handle->value;

        return match ($site->outcome) {
            SiteSyncOutcome::Registered => sprintf(
                'registered %s: site %s, root node %s, locales %s, changeset %s',
                $handle,
                self::id($site->site),
                self::id($site->root),
                self::locales($site->registered),
                $site->changeset instanceof ChangesetId ? $site->changeset->toString() : '-',
            ),
            SiteSyncOutcome::Unchanged => sprintf('unchanged %s: site %s, locales %s', $handle, self::id($site->site), self::locales($site->registered)),
            SiteSyncOutcome::Drifted => sprintf(
                'drifted %s: site %s publishes in %s, the configuration says %s; left unchanged',
                $handle,
                self::id($site->site),
                self::locales($site->registered),
                self::locales($site->configured),
            ),
            SiteSyncOutcome::Rejected => sprintf('rejected %s: nothing was written', $handle),
        };
    }

    private static function id(SiteId|NodeId|null $id): string
    {
        return $id instanceof SiteId || $id instanceof NodeId ? $id->toString() : '-';
    }

    /**
     * @param  list<Locale>  $locales
     */
    private static function locales(array $locales): string
    {
        return implode(', ', array_map(static fn (Locale $locale): string => $locale->value, $locales));
    }
}
