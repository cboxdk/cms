<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\MaintenanceProcessRefusal;
use Cbox\Cms\Cli\Boundary\RefusalOutput;
use Cbox\Cms\Cli\Boundary\ServiceCreateInput;
use Cbox\Cms\Cli\Boundary\ServiceCreateOutput;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Maintenance\Actions\RegisterServiceActor;
use Cbox\Cms\Core\Maintenance\Domain\ServiceRegistrationRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:service:create --email=<address> --name=<display name> --responsible=<staff actor>`:
 * registers a service actor as the installation operator (PRD 5.16), through the action
 * RegisterServiceActor: actor.register with the active staff actor responsible for it, then
 * actor.activate. It prints the actor's id. The actor holds no grant and has no credential yet:
 * grant.assign gives it a role, and cms:credential:issue its credential.
 *
 * Exit codes come from the error catalog (GUARDRAILS 2.1), through ServiceCreateOutput: 0 done;
 * 64 for a usage error; 78 outside the maintenance process (maintenance_process_required) and
 * before cms:install (installation_operator_missing); and the exit code of the first error of a
 * rejected command, such as 65 for a responsible person that is not an active staff actor.
 */
#[Internal]
#[Description('Register a service actor as the installation operator, with the staff actor responsible for it, and print its actor id')]
#[Signature('cms:service:create
        {--email= : The contact email address of the service actor}
        {--name= : The display name}
        {--responsible= : The UUIDv7 of the active staff actor responsible for the service actor}')]
final class ServiceCreateCommand extends Command
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
        $process = MaintenanceProcessRefusal::of($app, $config, 'cms:service:create');

        if ($process instanceof CatalogError) {
            return $refusals->errors(false, $process);
        }

        try {
            $registration = ServiceCreateInput::read($this->option('email'), $this->option('name'), $this->option('responsible'));
        } catch (CliCallRefused $refused) {
            return $refusals->refused($refused, false);
        }

        try {
            return ServiceCreateOutput::registered($app->make(RegisterServiceActor::class)->register($registration));
        } catch (ServiceRegistrationRefused $refusal) {
            return new ServiceCreateOutput($refusals)->refused($refusal);
        }
    }
}
