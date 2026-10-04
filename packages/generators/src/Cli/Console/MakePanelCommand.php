<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Cli\Console;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Generators\Cli\Boundary\MakePanelInput;
use Cbox\Cms\Generators\Cli\Domain\MakePanelRefused;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Scaffold\Actions\ScaffoldContribution;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * `cms:make:panel <fill|action|check|step> <namespace> <id>`: scaffolds one panel contribution of
 * an installed addon (PRD 13.4, section 7 of the panel extension architecture): the stub of its
 * kind with a test on the SDK's conformance helper, its entry in the registration module and the
 * ids module, and, for a contribution the manifest does not have yet, the line to add to the
 * manifest. A contribution cms:build compiled needs only its id; another needs --point, and a
 * check, a step or an action --command.
 *
 * Exit codes, from the error catalog's entry of the first problem's code: 0 written, 64 a bad
 * argument, an unknown addon or point, or a kind the point does not take, 66 a command or query
 * has no codec, 73 a file could not be written, 78 the registry or the addon's composer.json
 * cannot be read.
 */
#[Internal]
#[Description('Scaffold a panel contribution of an installed addon: a fill, an action, a form check or a flow step')]
#[Signature('cms:make:panel
        {kind : fill, action, check or step}
        {namespace : The addon\'s namespace, as its manifest names it}
        {id : The contribution\'s id, such as approvals.badge}
        {--point= : The point the contribution is on, <name>@<version>, for a contribution the manifest does not have yet}
        {--command= : The command a check, a step or an action is on, <name>@<version>}
        {--query= : The data query of a fill, <name>@<version>}
        {--severity=warning : The severity of a check: info, warning, acknowledge or error}
        {--position=before_submit : Where a step runs: before_submit or after_receipt}
        {--patch=* : A path a step patches, such as fields.ext.approvals.reason}')]
final class MakePanelCommand extends Command
{
    public function handle(ScaffoldContribution $scaffold): int
    {
        try {
            $request = MakePanelInput::read($this->arguments(), $this->options());
        } catch (MakePanelRefused $refused) {
            $this->error($refused->getMessage());

            return ExitCode::Usage->value;
        }

        try {
            $report = $scaffold->scaffold($request);
        } catch (GenerationFailed $failed) {
            foreach ($failed->problems as $problem) {
                $this->error($problem->describe());
            }

            $this->error('Nothing was scaffolded.');

            return GenerateCommand::exitCode($failed->problems[0]->code);
        }

        MakeAddonUiCommand::print($this, $report);
        $this->info(sprintf('Scaffolded the %s %s.', $request->kind->value, $request->id->value));

        return self::SUCCESS;
    }
}
