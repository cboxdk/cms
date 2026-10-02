<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\SitesSyncOutput;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Routing\Boundary\SitesConfig;
use Cbox\Cms\Core\Structure\Actions\SyncSites;
use Cbox\Cms\Core\Structure\Domain\Dto\SitesSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:sites:sync`: materialises the sites of `cbox-cms.sites` in the database (PRD 11.14, 5.8,
 * 5.9), in the maintenance process at deploy, after cms:install (the action SyncSites). Each
 * configured site the database lacks is registered with its root node and locales through
 * site.register as the installation operator, one changeset per site; a site registered with the
 * same locales is left alone, so a second run writes nothing; a site registered with other locales
 * is reported as site_locales_drift and left unchanged, and the other sites are still synced.
 *
 * Exit codes come from the error catalog (GUARDRAILS 2.1), through SitesSyncOutput: 0 when every
 * configured site is registered with its locales; 78 for a setting that cannot be read; otherwise
 * the exit code of the first error of the first site that did not sync, such as 65 for
 * site_locales_drift or 78 for installation_operator_missing.
 */
#[Internal]
#[Description('Register the configured sites the database lacks, with their root nodes and locales, as the installation operator')]
#[Signature('cms:sites:sync')]
final class SitesSyncCommand extends Command
{
    public function handle(Container $container, Repository $config): int
    {
        try {
            $sites = SitesConfig::read($config)->sites;
            $answer = null;
        } catch (InvalidArgumentException $invalid) {
            $sites = [];
            $answer = SitesSyncOutput::invalidConfiguration($invalid);
        }

        $answer ??= SitesSyncOutput::of($container->make(SyncSites::class)->sync(new SitesSync($sites)));

        foreach ($answer->output as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        foreach ($answer->errors as $line) {
            $this->output->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $answer->exit->value;
    }
}
