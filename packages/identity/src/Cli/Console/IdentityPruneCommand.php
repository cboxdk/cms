<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Cli\Console;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\PasswordReset\Actions\PruneResetTokens;
use Cbox\Cms\Identity\PasswordReset\Boundary\PasswordResetCommandOutput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:identity:prune`: removes the password reset tokens used or expired more than 24 hours ago
 * (PRD 5.16), through PruneResetTokens. The maintenance process schedules it every hour, and it runs
 * only there. Exit codes are PasswordResetCommandOutput's.
 */
#[Internal]
#[Description('Remove password reset tokens used or expired more than 24 hours ago, in the maintenance process')]
#[Signature('cms:identity:prune')]
final class IdentityPruneCommand extends Command
{
    public const string NAME = 'cms:identity:prune';

    public function handle(Application $app, Repository $config): int
    {
        $answer = PasswordResetCommandOutput::refusedProcess($app, $config, self::NAME)
            ?? PasswordResetCommandOutput::pruned($app->make(PruneResetTokens::class)->prune());

        foreach ($answer->output as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        foreach ($answer->errors as $line) {
            $this->output->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $answer->exit->value;
    }
}
