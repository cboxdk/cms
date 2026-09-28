<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Cli\Console;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Editor\Actions\WriteEditorLines;
use Cbox\Cms\Generators\Editor\Domain\Dto\EditorTarget;
use Cbox\Cms\Generators\Generation\Boundary\GeneratorConfig;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * `cms:schema:editor`: points editors at the blueprint schema (PRD 14.1, blueprint decision 3). It
 * gives every `*.yaml` file below the schema roots of cbox-cms.generators.roots, the files cms:generate
 * reads, the first line `# yaml-language-server: $schema=<path>`. The path is relative from the
 * file's directory to blueprint.v1.json in the installed cboxdk/cms, found through Composer: in an
 * application always through its install directory, vendor/cboxdk/cms, and in the package's own
 * repository, where cboxdk/cms is the root package, through the root, so the line has the same form
 * in every installation, and the same form in every checkout and worktree of the repository.
 *
 * A wrong line is replaced, never duplicated, and every other byte of a file is kept. Only files
 * whose bytes change are written, and each is named; a second run changes nothing. A schema root
 * below vendor/, such as an addon's `vendor/acme/shop/schema`, is skipped and named: Composer
 * installs those files, and the installation does not edit them.
 *
 * Exit codes, as cms:generate's: 0 every file has the line, 66 a schema root or a file cannot be
 * read, 73 a file could not be written, 78 the configuration is invalid or cboxdk/cms is not
 * installed. A file that fails does not stop the others.
 */
#[Internal]
#[Description('Write the yaml-language-server line that points editors at the blueprint schema into every blueprint file')]
#[Signature('cms:schema:editor')]
final class SchemaEditorCommand extends Command
{
    public function handle(WriteEditorLines $write, BlueprintSchemaFile $schema, Repository $config, Application $app): int
    {
        try {
            $report = $write->write(new EditorTarget(
                GeneratorConfig::read($config, $app->basePath())->roots,
                $schema->editorPath(),
            ));
        } catch (GenerationFailed $failed) {
            foreach ($failed->problems as $problem) {
                $this->error($problem->describe());
            }

            $this->error('No blueprint file was changed.');

            return GenerateCommand::exitCode($failed->problems[0]->code);
        }

        foreach ($report->skipped as $root) {
            $this->line(sprintf('skipped: %s, a schema root below vendor/ whose files Composer installs', $root));
        }

        foreach ($report->changed as $file) {
            $this->line('changed: '.$file);
        }

        $total = count($report->changed) + count($report->unchanged) + count($report->problems);
        $summary = sprintf(
            'Checked %d blueprint %s: %d changed, %d unchanged',
            $total,
            $total === 1 ? 'file' : 'files',
            count($report->changed),
            count($report->unchanged),
        );

        if ($report->skipped !== []) {
            $summary .= sprintf(', %d schema %s below vendor/ skipped', count($report->skipped), count($report->skipped) === 1 ? 'root' : 'roots');
        }

        if ($report->problems === []) {
            $this->info($summary.'.');

            return self::SUCCESS;
        }

        foreach ($report->problems as $problem) {
            $this->error($problem->describe());
        }

        $this->error(sprintf('%s, %d failed.', $summary, count($report->problems)));

        return GenerateCommand::exitCode($report->problems[0]->code);
    }
}
