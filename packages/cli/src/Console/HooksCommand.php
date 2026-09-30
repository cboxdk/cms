<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\HookMapInput;
use Cbox\Cms\Cli\Boundary\HookMapOutput;
use Cbox\Cms\Cli\Boundary\RefusalOutput;
use Cbox\Cms\Cli\Boundary\RegistryRefusal;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Actions\MapHooks;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\UnknownCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:hooks <name>` (PRD 13.2, GUARDRAILS 7.1): the hook map of a command, every hook that runs
 * for each of its versions in the order the command pipeline runs them (phase, lowest priority
 * first, package, class), with its addon and budget. It calls the action MapHooks, and
 * HookMapOutput prints the map, as JSON with --json.
 *
 * Exit codes: 0; 64 for a name that is not a command's or names no registered command, a query's
 * included; 78 when the registry cache is missing or damaged.
 */
#[Internal]
#[Description('Show the hooks that run for a command, in the order they run, with their budgets')]
#[Signature('cms:hooks
        {name : The command\'s name, such as entry.create}
        {--json : Print the hook map as one JSON document}')]
final class HooksCommand extends Command
{
    public function handle(MapHooks $hooks, HookMapOutput $output, RefusalOutput $refusals): int
    {
        $json = $this->option('json') === true;

        try {
            $answer = $output->of($hooks->map(HookMapInput::read($this->argument('name'))), $json);
        } catch (CliCallRefused $refused) {
            $answer = $refusals->refused($refused, $json);
        } catch (UnknownCommand $unknown) {
            $answer = $refusals->refused(CliCallRefused::usage($unknown->getMessage(), $unknown), $json);
        } catch (RegistryCacheMissing|MalformedRegistryCache $failed) {
            $answer = $refusals->refused(RegistryRefusal::of($failed), $json);
        }

        foreach ($answer->output as $line) {
            $this->output->writeln($line, $json ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);
        }

        foreach ($answer->errors as $line) {
            $this->output->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $answer->exit->value;
    }
}
