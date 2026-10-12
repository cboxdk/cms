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
use Cbox\Cms\Core\Structure\Domain\Dto\LocaleChange;
use Cbox\Cms\Core\Structure\Domain\Dto\SitesSyncReport;
use Cbox\Cms\Core\Structure\Domain\Dto\SiteSync;
use Cbox\Cms\Core\Structure\Domain\LocaleChangeKind;
use Cbox\Cms\Core\Structure\Domain\SiteSyncOutcome;
use InvalidArgumentException;

/**
 * What cms:sites:sync answers (PRD 11.14, GUARDRAILS 2.1), with exit codes from the error catalog:
 * one line per configured site on standard output, with the root node of a registered site also
 * when it was registered before, since a first grant such as cms:access:bootstrap's needs a node,
 * and each locale added or removed with its changeset; each error of a site that did not sync on
 * standard error with its catalog code, and the exit code 0 when every site synced, or the exit
 * code of the first error of the first site that did not; ExitCode::Config for a setting that
 * cannot be read. A dry run says so first and writes each line as what would happen.
 */
#[Internal]
final readonly class SitesSyncOutput
{
    /** The first line of a dry run. */
    public const string DRY_RUN = 'dry run: nothing is written';

    public static function of(SitesSyncReport $report): CliAnswer
    {
        $output = $report->dryRun ? [self::DRY_RUN] : [];
        $errors = [];

        foreach ($report->sites as $site) {
            $output[] = self::line($site, $report->dryRun);

            foreach ($site->allErrors() as $error) {
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

    private static function line(SiteSync $site, bool $dryRun): string
    {
        $handle = $site->handle->value;

        return match ($site->outcome) {
            SiteSyncOutcome::Registered => $dryRun
                ? sprintf('would register %s: locales %s', $handle, self::locales($site->configured))
                : sprintf(
                    'registered %s: site %s, root node %s, locales %s, changeset %s',
                    $handle,
                    self::id($site->site),
                    self::id($site->root),
                    self::locales($site->registered),
                    self::changeset($site->changeset),
                ),
            SiteSyncOutcome::Unchanged => sprintf('unchanged %s: site %s, root node %s, locales %s', $handle, self::id($site->site), self::id($site->root), self::locales($site->registered)),
            SiteSyncOutcome::Reconciled => sprintf(
                '%s %s: site %s, root node %s, %s%s',
                $dryRun ? 'would reconcile' : 'reconciled',
                $handle,
                self::id($site->site),
                self::id($site->root),
                self::changes($site->changes, $dryRun),
                $dryRun ? '' : ', locales '.self::locales($site->registered),
            ),
            SiteSyncOutcome::Rejected => $site->site instanceof SiteId
                ? sprintf(
                    '%s %s: site %s, root node %s, %s, locales %s',
                    $dryRun ? 'would reject' : 'rejected',
                    $handle,
                    self::id($site->site),
                    self::id($site->root),
                    self::changes($site->changes, $dryRun),
                    self::locales($site->registered),
                )
                : sprintf('%s %s: nothing %s written', $dryRun ? 'would reject' : 'rejected', $handle, $dryRun ? 'would be' : 'was'),
        };
    }

    /**
     * @param  list<LocaleChange>  $changes
     */
    private static function changes(array $changes, bool $dryRun): string
    {
        return implode(', ', array_map(static fn (LocaleChange $change): string => sprintf(
            '%s %s%s',
            $change->kind === LocaleChangeKind::Added ? ($dryRun ? 'add' : 'added') : ($dryRun ? 'remove' : 'removed'),
            $change->locale->value,
            $change->rejected() ? ' (refused)' : ($dryRun ? '' : ' (changeset '.self::changeset($change->changeset).')'),
        ), $changes));
    }

    private static function changeset(?ChangesetId $changeset): string
    {
        return $changeset instanceof ChangesetId ? $changeset->toString() : '-';
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
