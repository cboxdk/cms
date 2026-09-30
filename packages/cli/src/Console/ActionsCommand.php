<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\ActionsOutput;
use Cbox\Cms\Cli\Boundary\RefusalOutput;
use Cbox\Cms\Cli\Boundary\RegistryRefusal;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Actions\DescribeActions;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:actions` (GUARDRAILS 7.1): every action of the compiled registry with the command or query
 * it handles, its surfaces, the permission a grant needs to allow it and its hooks in the order
 * they run. It calls the action DescribeActions, and ActionsOutput prints the result, as JSON with
 * --json.
 *
 * Exit codes: 0; 78 when the registry cache is missing or damaged (registry_cache_missing,
 * registry_cache_malformed).
 */
#[Internal]
#[Description('List every action with the command or query it handles, its surfaces, grants and hooks')]
#[Signature('cms:actions
        {--json : Print the actions as one JSON document}')]
final class ActionsCommand extends Command
{
    public function handle(DescribeActions $actions, ActionsOutput $output, RefusalOutput $refusals): int
    {
        $json = $this->option('json') === true;

        try {
            $answer = $output->of($actions->describe(), $json);
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
