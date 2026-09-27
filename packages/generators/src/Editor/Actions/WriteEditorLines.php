<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Editor\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Editor\Domain\Dto\EditorReport;
use Cbox\Cms\Generators\Editor\Domain\Dto\EditorTarget;
use Cbox\Cms\Generators\Editor\Domain\EditorLine;
use Cbox\Cms\Generators\Editor\Domain\SchemaFiles;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * cms:schema:editor (PRD 14.1, blueprint decision 3): gives every blueprint file below the target's
 * schema roots the editor line `# yaml-language-server: $schema=<path>` as its first line, with the
 * path relative from the file's directory to the target's schema. A file that already has the
 * line keeps its bytes and is not written; a file with another `$schema` line gets it replaced.
 *
 * A schema root below vendor/ (SchemaRoot::belowVendor()), an addon's root such as
 * `vendor/acme/shop/schema`, is skipped and named in the report: Composer installs those files and
 * would see an edited file as a changed package, and the installation does not own them. Its
 * files are neither listed nor read, and cms:generate still reads them.
 *
 * A file that cannot be read or written is reported and the other files are still edited, so one
 * run shows every problem. Each file is written all or nothing.
 */
#[Internal]
final readonly class WriteEditorLines
{
    public function __construct(private SchemaFiles $files) {}

    /**
     * @throws GenerationFailed with generate_invalid_config or generate_schema_missing when the
     *                          files below the roots that are not skipped cannot be listed;
     *                          nothing is written then
     */
    public function write(EditorTarget $target): EditorReport
    {
        $changed = [];
        $unchanged = [];
        $problems = [];
        $roots = [];
        $skipped = [];

        foreach ($target->roots as $root) {
            if ($root->belowVendor()) {
                $skipped[] = $root->directory;
            } else {
                $roots[] = $root;
            }
        }

        sort($skipped);

        foreach ($roots === [] ? [] : $this->files->find($roots) as $file) {
            try {
                $contents = $this->files->read($file);
                $edited = EditorLine::towards($target->schema, $file->directory)->apply($contents);

                if ($edited === $contents) {
                    $unchanged[] = $file->file;

                    continue;
                }

                $this->files->write($file, $edited);
                $changed[] = $file->file;
            } catch (GenerationFailed $failed) {
                array_push($problems, ...$failed->problems);
            }
        }

        usort($problems, static fn (GenerationProblem $a, GenerationProblem $b): int => [$a->code->value, $a->message] <=> [$b->code->value, $b->message]);

        return new EditorReport($changed, $unchanged, $problems, $skipped);
    }
}
