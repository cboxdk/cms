<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Cli\Console;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Identity\Staff\Actions\RegisterLocalStaff;
use Cbox\Cms\Identity\Staff\Boundary\StaffCreateInput;
use Cbox\Cms\Identity\Staff\Boundary\StaffCreateOutput;
use Cbox\Cms\Identity\Staff\Domain\StaffRegistrationRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:staff:create --email=<address> --name=<display name> [--password-stdin]`: registers a local
 * staff member as the installation operator (PRD 5.16), through the action RegisterLocalStaff:
 * actor.register, the credential, actor.activate. It prints the actor's id.
 *
 * The password is read hidden from the terminal and typed twice, or with --password-stdin from
 * standard input; it is never an argument or an option, so it never reaches the shell's history or
 * the process list. Exit codes come from the error catalog (GUARDRAILS 2.1): 0; 64 for a usage
 * error; 65 for a password the policy refuses, an email whose login has an account and a refused
 * command; 75 when the breach check cannot be made; 78 before cms:install
 * (installation_operator_missing).
 */
#[Internal]
#[Description('Register a local staff member as the installation operator, and print its actor id')]
#[Signature('cms:staff:create {--email= : The email address, which is also the login} {--name= : The display name} {--password-stdin : Read the password from standard input instead of the terminal}')]
final class StaffCreateCommand extends Command
{
    public function handle(RegisterLocalStaff $staff): int
    {
        $email = $this->option('email');
        $name = $this->option('name');
        $answer = StaffCreateInput::options($email, $name);

        if (! $answer instanceof CliAnswer && is_string($email) && is_string($name)) {
            $password = match (true) {
                $this->option('password-stdin') === true => StaffCreateInput::fromStream(STDIN),
                $this->input->isInteractive() => StaffCreateInput::typedTwice($this->secret('Password'), $this->secret('Repeat the password')),
                default => null,
            };
            $registration = StaffCreateInput::registration($email, $name, $password);

            try {
                $answer = $registration instanceof CliAnswer ? $registration : StaffCreateOutput::registered($staff->register($registration));
            } catch (StaffRegistrationRefused|BreachedPasswordsUnavailable $refusal) {
                $answer = StaffCreateOutput::refused($refusal);
            }
        }

        $answer ??= StaffCreateInput::usage('cms:staff:create needs --email=<address> and --name=<display name>.');

        foreach ($answer->output as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        foreach ($answer->errors as $line) {
            $this->output->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $answer->exit->value;
    }
}
