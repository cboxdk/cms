<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\PanelPointsInput;
use Cbox\Cms\Cli\Boundary\PanelPointsOutput;
use Cbox\Cms\Cli\Boundary\RefusalOutput;
use Cbox\Cms\Cli\Boundary\RegistryRefusal;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Actions\DescribePanelPoints;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\UnknownPanelPoint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:panel:points [selector]` (PRD 13.2, 13.4, GUARDRAILS 7.1): the panel's extension points as
 * the registry holds them, by name and version, all of them or those a page, a name or an id
 * selects, each with its kind, region, multiplicity, page, stability, release, props class and the
 * number of contributions to it. It calls the action DescribePanelPoints, and PanelPointsOutput
 * prints the points, as JSON with --json.
 *
 * Exit codes: 0; 64 for a selector that is not a page's name or a point's id, or that selects no
 * point; 78 when the registry cache is missing or damaged.
 */
#[Internal]
#[Description('List the panel\'s extension points with their kind, page, stability and number of contributions')]
#[Signature('cms:panel:points
        {selector? : A page, a point\'s name or a point\'s id, such as account.me or account.me.sections@1}
        {--json : Print the points as one JSON document}')]
final class PanelPointsCommand extends Command
{
    public function handle(DescribePanelPoints $points, PanelPointsOutput $output, RefusalOutput $refusals): int
    {
        $json = $this->option('json') === true;

        try {
            $answer = $output->points($points->describe(PanelPointsInput::points($this->argument('selector'))), $json);
        } catch (CliCallRefused $refused) {
            $answer = $refusals->refused($refused, $json);
        } catch (UnknownPanelPoint $unknown) {
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
