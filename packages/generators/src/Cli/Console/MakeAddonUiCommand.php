<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Cli\Console;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Scaffold\Actions\ScaffoldAddonUi;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\AddonUiRequest;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * `cms:make:addon-ui <namespace>`: scaffolds the panel UI of an installed addon into its package
 * (PRD 13.4, section 7 of the panel extension architecture): the generated types, package.json
 * with the SDK, the TypeScript, Vite, Vitest, ESLint and Prettier configuration, the registration
 * module with its test, a stub with a test per fill, form check and flow step cms:build compiled
 * from the manifest, and the PHP test that runs the testkit's PanelContributionsContract. A file
 * the addon has is kept; the ids module is written anew from the registry.
 *
 * Exit codes, from the error catalog's entry of the first problem's code: 0 written, 64 no
 * installed addon has the namespace, 65 a schema has no TypeScript form, 66 a command or query
 * has no codec, 73 a file could not be written, 78 the registry or the addon's composer.json
 * cannot be read.
 */
#[Internal]
#[Description('Scaffold the panel UI of an installed addon into its package')]
#[Signature('cms:make:addon-ui {namespace : The addon\'s namespace, as its manifest names it}')]
final class MakeAddonUiCommand extends Command
{
    public function handle(ScaffoldAddonUi $scaffold): int
    {
        try {
            $namespace = new AddonNamespace((string) $this->argument('namespace'));
        } catch (InvalidAddonManifest|ReservedAddonNamespace $invalid) {
            $this->error($invalid->getMessage());

            return ExitCode::Usage->value;
        }

        try {
            $report = $scaffold->scaffold(new AddonUiRequest($namespace));
        } catch (GenerationFailed $failed) {
            foreach ($failed->problems as $problem) {
                $this->error($problem->describe());
            }

            $this->error('Nothing was scaffolded.');

            return GenerateCommand::exitCode($failed->problems[0]->code);
        }

        self::print($this, $report);
        $this->info(sprintf('Scaffolded the panel UI of %s.', $namespace->value));

        return self::SUCCESS;
    }

    /**
     * Prints what a scaffold wrote and kept, and its notes.
     */
    public static function print(Command $command, ScaffoldReport $report): void
    {
        foreach ($report->written as $path) {
            $command->line('written: '.$path);
        }

        foreach ($report->kept as $path) {
            $command->line('kept: '.$path);
        }

        foreach ($report->notes as $note) {
            $command->warn($note);
        }
    }
}
