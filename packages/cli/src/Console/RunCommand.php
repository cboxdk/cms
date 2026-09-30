<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\ExposedCommandInput;
use Cbox\Cms\Cli\Boundary\ExposedCommandOutput;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:run <name> <version> <document>`: the CLI surface (GUARDRAILS 2.1). It runs every write
 * action whose #[Action] lists Surface::Cli, one generic command whose accepted names and versions
 * are those the compiled registry exposes on the CLI. ExposedCommandInput reads the call, the
 * shared core action RunExposedCommand runs it as the actor of the process's configured service
 * credential, `cbox-cms.cli.credential`, and ExposedCommandOutput translates the typed result into
 * the exit code of the error catalog and the receipt or problem, as JSON with --json. The command
 * holds no logic of its own.
 */
#[Internal]
#[Description('Run a write command the registry exposes on the CLI, as the actor of the configured service credential')]
#[Signature('cms:run
        {name : The command\'s name, such as entry.create}
        {version : The command\'s version, such as 1}
        {document : The command as its JSON document}
        {--idempotency-key= : The idempotency key; a repeat with the same key and document gives the same receipt}
        {--dry-run : Compute the plan and the receipt and commit nothing}
        {--wait-level= : commit (the default), origin, edge, verified or propagated}
        {--json : Print the receipt, or the problem details of a rejection, as JSON}')]
final class RunCommand extends Command
{
    public function handle(ExposedCommandInput $input, ExposedCommandOutput $output): int
    {
        $json = $this->option('json') === true;

        try {
            $call = $input->read(
                $this->argument('name'),
                $this->argument('version'),
                $this->argument('document'),
                $this->option('idempotency-key'),
                $this->option('dry-run'),
                $this->option('wait-level'),
            );
            $answer = $output->of($this->laravel->make(RunExposedCommand::class)->run($call), $json);
        } catch (CliCallRefused $refused) {
            $answer = $output->refused($refused, $json);
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
