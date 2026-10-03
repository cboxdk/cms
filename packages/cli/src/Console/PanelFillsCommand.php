<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\PanelPointsInput;
use Cbox\Cms\Cli\Boundary\PanelPointsOutput;
use Cbox\Cms\Cli\Boundary\RefusalOutput;
use Cbox\Cms\Cli\Boundary\RegistryRefusal;
use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Actions\MapPanelFills;
use Cbox\Cms\Core\Registry\Domain\InvalidPanelActivation;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Registry\Domain\UnknownPanelPoint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `cms:panel:fills <point>` (PRD 13.2, 13.4, GUARDRAILS 7.1): the contributions to a panel point
 * in the order the host renders them, priority with the lowest first, then the addon's namespace,
 * then the contribution's id, each with its package, the scope it is narrowed to, and where its
 * order and enabled state come from: the addon, the installation's settings that cms:build
 * compiled, or the activation state of now (cbox-cms.panel.disabled). It calls the action
 * MapPanelFills, and PanelPointsOutput prints them, as JSON with --json.
 *
 * Exit codes: 0; 64 for an argument that is not a point's id, or the id of no registered point; 78
 * when the registry cache is missing or damaged, or the activation state is not of its form.
 */
#[Internal]
#[Description('List the contributions to a panel point in the order the panel renders them')]
#[Signature('cms:panel:fills
        {point : The id of a panel point, such as account.me.sections@1}
        {--json : Print the contributions as one JSON document}')]
final class PanelFillsCommand extends Command
{
    public function handle(MapPanelFills $fills, PanelPointsOutput $output, RefusalOutput $refusals): int
    {
        $json = $this->option('json') === true;

        try {
            $answer = $output->fills($fills->map(PanelPointsInput::fills($this->argument('point'))), $json);
        } catch (CliCallRefused $refused) {
            $answer = $refusals->refused($refused, $json);
        } catch (UnknownPanelPoint $unknown) {
            $answer = $refusals->refused(CliCallRefused::usage($unknown->getMessage(), $unknown), $json);
        } catch (InvalidPanelActivation $invalid) {
            $answer = $refusals->refused(CliCallRefused::config($invalid->getMessage(), $invalid), $json);
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
