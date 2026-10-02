<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\AccessBootstrapInput;
use Cbox\Cms\Cli\Boundary\AccessBootstrapOutput;
use Cbox\Cms\Cli\Boundary\RefusalOutput;
use Cbox\Cms\Cli\Boundary\RegistryRefusal;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Maintenance\Actions\BootstrapAccess;
use Cbox\Cms\Core\Maintenance\Boundary\BootstrapConfig;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:access:bootstrap <actor> <node>`: the one-time access bootstrap (PRD 5.10, 5.16), in the
 * maintenance process, as the installation operator (the action BootstrapAccess). It creates the
 * bootstrap role named by cbox-cms.access.bootstrap_role, with every command and query of the
 * registry and the ceiling sensitive, and grants it to the active staff actor on the node. Once any
 * staff member holds a grant it is refused, and in the production environment it never runs.
 *
 * Exit codes come from the error catalog (GUARDRAILS 2.1), through AccessBootstrapOutput: 0 done;
 * 64 for arguments that are not two UUIDv7s; 78 outside the maintenance process
 * (maintenance_process_required), for a bootstrap_role that is not a role handle, and for a
 * missing or damaged registry cache; and the exit code of the first error of a refusal or of a
 * rejected command, such as 77 for access_bootstrap_done and access_bootstrap_production.
 */
#[Internal]
#[Description('Give the first staff member the bootstrap role on a node, once, as the installation operator')]
#[Signature('cms:access:bootstrap
        {actor : The UUIDv7 of the active staff actor that gets the bootstrap role}
        {node : The UUIDv7 of the node it gets the role on}')]
final class AccessBootstrapCommand extends Command
{
    public function handle(Application $app, Repository $config, RefusalOutput $refusals): int
    {
        $answer = $this->answer($app, $config, $refusals);

        foreach ($answer->output as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        foreach ($answer->errors as $line) {
            $this->output->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $answer->exit->value;
    }

    private function answer(Application $app, Repository $config, RefusalOutput $refusals): CliAnswer
    {
        $process = AccessBootstrapOutput::refusedProcess($app, $config);

        if ($process instanceof CatalogError) {
            return $refusals->errors(false, $process);
        }

        try {
            $request = AccessBootstrapInput::read($this->argument('actor'), $this->argument('node'));
        } catch (CliCallRefused $refused) {
            return $refusals->refused($refused, false);
        }

        try {
            BootstrapConfig::read($config, $app->environment());
        } catch (InvalidArgumentException $invalid) {
            return $refusals->refused(CliCallRefused::config($invalid->getMessage(), $invalid), false);
        }

        try {
            $outcome = $app->make(BootstrapAccess::class)->run($request);
        } catch (RegistryCacheMissing|MalformedRegistryCache $failed) {
            return $refusals->refused(RegistryRefusal::of($failed), false);
        }

        return new AccessBootstrapOutput($refusals)->of($outcome);
    }
}
