<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\ExplainInput;
use Cbox\Cms\Cli\Boundary\ExplainOutput;
use Cbox\Cms\Cli\Boundary\RegistryRefusal;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:explain <url> --locale=<locale>` (GUARDRAILS 5 and 7.1): why the page at a URL looks as it
 * does to the public. ExplainInput reads the URL into the read of path.resolve as the anonymous
 * principal, the query pipeline runs it as it runs every read, and ExplainOutput prints the typed
 * explanation the action returns, step by step, with the content keys and the read's position, as
 * JSON with --json. There is no other explain code.
 *
 * Exit codes: 0 for an answered read, whatever its outcome; 64 for a URL or locale path.resolve does
 * not take; the catalog's exit code for a rejected read; 78 when the registry cache is missing or
 * damaged.
 */
#[Internal]
#[Description('Explain why the page at a URL looks as it does: the site, route, node, placement, visibility and canonical URL')]
#[Signature('cms:explain
        {url : The absolute URL of the page, such as https://example.dk/nyheder/harbour}
        {--locale= : The locale to resolve it in, such as da}
        {--json : Print the explanation as one JSON document}')]
final class ExplainCommand extends Command
{
    public function handle(ExplainOutput $output): int
    {
        $json = $this->option('json') === true;

        try {
            $answer = $output->of($this->laravel->make(QueryPipeline::class)->run(ExplainInput::read($this->argument('url'), $this->option('locale'))), $json);
        } catch (CliCallRefused $refused) {
            $answer = $output->refused($refused, $json);
        } catch (RegistryCacheMissing|MalformedRegistryCache $failed) {
            $answer = $output->refused(RegistryRefusal::of($failed), $json);
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
