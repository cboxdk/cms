<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Cli\Console;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Actions\WritePanelTypes;
use Cbox\Cms\Generators\PanelTypes\Domain\ContributionsModule;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\PanelTypesRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * `cms:panel:types <namespace>`: the TypeScript of an addon's panel contributions (PRD 13.4,
 * section 4.4 of the panel extension architecture). It reads the contributions cms:build compiled
 * from the addon's manifest and writes resources/panel/generated/contributions.ts below the addon's
 * package: Contributions, which definePanelAddon<Contributions>() of @cboxdk/cms-panel/extend
 * takes, Issues, the commands usePanelHost<Issues>() may issue, and the documents they exchange.
 * The output is deterministic, so a second run changes nothing, and the addon's own
 * check:generated keeps it current.
 *
 * Exit codes, from the error catalog's entry of the first problem's code: 0 written, 64 no
 * installed addon has the namespace, 65 a schema has no TypeScript form or two types get one name,
 * 66 a command or query has no codec, 73 the file could not be written, 78 the registry cannot be
 * read.
 */
#[Internal]
#[Description('Write the TypeScript of an addon\'s panel contributions')]
#[Signature('cms:panel:types {namespace : The addon\'s namespace, as its manifest names it}')]
final class PanelTypesCommand extends Command
{
    public function handle(WritePanelTypes $types): int
    {
        try {
            $namespace = new AddonNamespace((string) $this->argument('namespace'));
        } catch (InvalidAddonManifest|ReservedAddonNamespace $invalid) {
            $this->error($invalid->getMessage());

            return ExitCode::Usage->value;
        }

        try {
            $report = $types->write(new PanelTypesRequest($namespace));
        } catch (GenerationFailed $failed) {
            foreach ($failed->problems as $problem) {
                $this->error($problem->describe());
            }

            $this->error('Nothing was written, and the panel types were left as they were.');

            return GenerateCommand::exitCode($failed->problems[0]->code);
        }

        foreach ($report->written as $path) {
            $this->line('written: '.$path);
        }

        foreach ($report->removed as $path) {
            $this->line('removed: '.$path);
        }

        $this->info($report->written === [] && $report->removed === []
            ? sprintf('The panel types of %s are current: %s.', $namespace->value, ContributionsModule::DIRECTORY.'/'.ContributionsModule::FILE)
            : sprintf('Wrote the panel types of %s.', $namespace->value));

        return self::SUCCESS;
    }
}
