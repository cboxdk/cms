<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\InstallOutput;
use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Maintenance\Actions\InstallOperator;
use Cbox\Cms\Core\Maintenance\Domain\InstallRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:install`: creates the installation operator once (PRD 5.16, 3.3), the service actor the
 * maintenance commands run as, in the maintenance process, as the owner role (the action
 * InstallOperator). The operator is registered and activated in one genesis changeset in which it
 * is its own actor, and its id is kept in the kernel table `installation`, so every process and every
 * deploy finds it. It is idempotent: a second run changes nothing and prints the operator's id.
 *
 * Exit codes come from the error catalog (GUARDRAILS 2.1), through InstallOutput: 0 done; 78 in a
 * process that serves HTTP or runs queued jobs (owner_credentials_exposed), and without the owner
 * connection or with one that is not the owner role's (install_owner_connection_required); 75 when
 * no partition covers the genesis (partition_missing: run cms:partitions:maintain first).
 */
#[Internal]
#[Description('Create the installation operator once, the service actor the maintenance commands run as, as the owner role')]
#[Signature('cms:install')]
final class InstallCommand extends Command
{
    public function handle(Application $app): int
    {
        $answer = InstallOutput::refusedWorkload($app);

        if (! $answer instanceof CliAnswer) {
            try {
                $answer = InstallOutput::of($app->make(InstallOperator::class)->install());
            } catch (InstallRefused|PartitionMissing $refusal) {
                $answer = InstallOutput::refused($refusal);
            }
        }

        foreach ($answer->output as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        foreach ($answer->errors as $line) {
            $this->output->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $answer->exit->value;
    }
}
