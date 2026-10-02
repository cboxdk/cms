<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Cli\Console;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Identity\PasswordReset\Actions\IssueResetLink;
use Cbox\Cms\Identity\PasswordReset\Boundary\PasswordResetCommandOutput;
use Cbox\Cms\Identity\PasswordReset\Domain\InvalidPasswordReset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:staff:reset-link <email>`: prints a password reset link for the local account whose login is
 * the email (PRD 5.16), for an operator who hands it to the person another way than by mail, such
 * as when the mail transport is down. It runs IssueResetLink, as the panel's page does, but sends
 * nothing, and runs only in the maintenance process. Exit codes are PasswordResetCommandOutput's.
 */
#[Internal]
#[Description('Print a password reset link for a local account, in the maintenance process')]
#[Signature('cms:staff:reset-link {email : The email address that is the login of the local account}')]
final class StaffResetLinkCommand extends Command
{
    public const string NAME = 'cms:staff:reset-link';

    public function handle(Application $app, Repository $config): int
    {
        $answer = PasswordResetCommandOutput::refusedProcess($app, $config, self::NAME) ?? $this->issue($app);

        foreach ($answer->output as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        foreach ($answer->errors as $line) {
            $this->output->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $answer->exit->value;
    }

    private function issue(Application $app): CliAnswer
    {
        $login = LoginIdentifier::typed($this->argument('email'));

        if (! $login instanceof LoginIdentifier) {
            return PasswordResetCommandOutput::usage('cms:staff:reset-link needs the email address of a local account.');
        }

        try {
            return PasswordResetCommandOutput::link($app->make(IssueResetLink::class)->issue($login));
        } catch (InvalidPasswordReset $invalid) {
            return PasswordResetCommandOutput::invalidSettings($invalid);
        }
    }
}
