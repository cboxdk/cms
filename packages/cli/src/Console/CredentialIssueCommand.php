<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\CredentialIssueInput;
use Cbox\Cms\Cli\Boundary\CredentialIssueOutput;
use Cbox\Cms\Cli\Boundary\MaintenanceProcessRefusal;
use Cbox\Cms\Cli\Boundary\RefusalOutput;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Maintenance\Actions\IssueServiceCredential;
use Cbox\Cms\Core\Maintenance\Domain\CredentialIssueRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:credential:issue <actor> [--ceiling=internal] [--days=90]`: issues a service credential to
 * an active service actor (PRD 5.16), as the owner role in the maintenance process, through the
 * action IssueServiceCredential. It prints the token once, alone on standard output; the store
 * keeps only its hash. The token is the Bearer credential of the REST API and
 * `cbox-cms.cli.credential` of cms:run, with the issuer kind service, the ceiling given and an
 * expiry the given number of days from now.
 *
 * Exit codes come from the error catalog (GUARDRAILS 2.1), through CredentialIssueOutput: 0 done;
 * 64 for a usage error; 78 outside the maintenance process (maintenance_process_required); 65 for
 * an actor that is not a service actor (validation_failed); 77 for one that is not active
 * (actor_not_active).
 */
#[Internal]
#[Description('Issue a service credential to an active service actor, as the owner role, and print its token once')]
#[Signature('cms:credential:issue
        {actor : The UUIDv7 of the active service actor}
        {--ceiling=internal : The classification ceiling of the credential: public, internal, confidential, personal or sensitive}
        {--days=90 : How many days from now the credential lives, 1 to 365}')]
final class CredentialIssueCommand extends Command
{
    public function handle(Application $app, Repository $config, RefusalOutput $refusals, Clock $clock): int
    {
        $answer = $this->answer($app, $config, $refusals, $clock);

        foreach ($answer->output as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        foreach ($answer->errors as $line) {
            $this->output->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $answer->exit->value;
    }

    private function answer(Application $app, Repository $config, RefusalOutput $refusals, Clock $clock): CliAnswer
    {
        $process = MaintenanceProcessRefusal::of($app, $config, 'cms:credential:issue');

        if ($process instanceof CatalogError) {
            return $refusals->errors(false, $process);
        }

        try {
            $issue = CredentialIssueInput::read($this->argument('actor'), $this->option('ceiling'), $this->option('days'), $clock->now());
        } catch (CliCallRefused $refused) {
            return $refusals->refused($refused, false);
        }

        try {
            return CredentialIssueOutput::issued($app->make(IssueServiceCredential::class)->issue($issue));
        } catch (CredentialIssueRefused $refusal) {
            return new CredentialIssueOutput($refusals)->refused($refusal);
        }
    }
}
