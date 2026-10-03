<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Cli\Console;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelStories\Actions\WritePanelStories;
use Cbox\Cms\Generators\PanelStories\Domain\Dto\PanelStoriesRequest;
use Cbox\Cms\Generators\PanelStories\Domain\PanelStoriesModule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * `cms:panel:stories`: the stories of the panel's points (PRD 13.4, section 2.7 of the panel
 * extension architecture), the section "Panel points" of the Storybook of cboxdk/cms, which gate 7
 * tests: an overview and one story per point of panel.php, which cms:build compiles, so no point
 * exists without a story. It writes js/panel/stories/generated below the checkout of cboxdk/cms it is
 * part of, and runs only in a checkout, which has js/panel/stories; the output is deterministic, and
 * a test of gate 5 holds the committed stories to it.
 *
 * Exit codes, from the error catalog's entry of the first problem's code: 0 written, 65 a schema
 * has no sample or two points give one story, 73 a file could not be written, 78 the registry
 * cannot be read or this is no checkout of cboxdk/cms.
 */
#[Internal]
#[Description('Write the Storybook stories of the panel\'s points')]
#[Signature('cms:panel:stories')]
final class PanelStoriesCommand extends Command
{
    /** The package whose repository holds the panel's Storybook. */
    public const string PACKAGE = 'cboxdk/cms';

    /** The directory of the panel's stories in a checkout of the package. */
    public const string STORIES = 'js/panel/stories';

    /** The root of the package this command is part of: packages/generators/src/Cli/Console is five levels below it. */
    public static function root(): string
    {
        return dirname(__DIR__, 5);
    }

    public function handle(WritePanelStories $stories): int
    {
        $root = self::root();

        if (! is_dir($root.'/'.self::STORIES)) {
            $this->error(sprintf('cms:panel:stories writes the Storybook of the repository of %s, and runs only in a checkout of it, which has %s.', self::PACKAGE, self::STORIES));

            return ExitCode::Config->value;
        }

        try {
            $report = $stories->write(new PanelStoriesRequest($root));
        } catch (GenerationFailed $failed) {
            foreach ($failed->problems as $problem) {
                $this->error($problem->describe());
            }

            $this->error('Nothing was written, and the stories were left as they were.');

            return GenerateCommand::exitCode($failed->problems[0]->code);
        }

        foreach ($report->written as $path) {
            $this->line('written: '.$path);
        }

        foreach ($report->removed as $path) {
            $this->line('removed: '.$path);
        }

        $this->info($report->written === [] && $report->removed === []
            ? sprintf('The stories of the panel points are current: %s.', PanelStoriesModule::DIRECTORY)
            : 'Wrote the stories of the panel points.');

        return self::SUCCESS;
    }
}
