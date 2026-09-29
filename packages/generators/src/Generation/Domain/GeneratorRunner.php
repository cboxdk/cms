<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;

/**
 * Runs every generator over the schema and collects one deterministic result (PRD 11.12): the
 * files sorted by path and the owned directories sorted, whatever order the generators run in.
 *
 * It checks what the generators produced before anything is written: each owned directory ends
 * in "Generated" or "generated" or is the target's migrations directory, which ends in
 * "migrations/cms", each file lies below its generator's directory, and no two files share a path.
 */
#[Internal]
final readonly class GeneratorRunner
{
    /**
     * @param  list<Generator>  $generators
     */
    public function __construct(private array $generators) {}

    /**
     * @throws GenerationFailed
     */
    public function run(CompiledSchema $schema, GenerationTarget $target): GenerationResult
    {
        $files = [];
        $directories = [];
        $problems = [];

        foreach ($this->generators as $generator) {
            $directory = $generator->directory($target);
            $directories[$directory] = $directory;

            if (! in_array(basename($directory), ['Generated', 'generated'], true) && $directory !== $target->migrationsDirectory) {
                $problems[] = new GenerationProblem(GenerateErrorCode::InvalidOutput, sprintf(
                    '%s owns "%s", but an owned directory must be named "Generated" or "generated", or be the migrations directory, because cms:generate removes the files in it that it did not generate.',
                    $generator::class,
                    $directory,
                ));

                continue;
            }

            foreach ($generator->generate($schema, $target) as $file) {
                if (! str_starts_with($file->path, $directory.'/')) {
                    $problems[] = new GenerationProblem(GenerateErrorCode::InvalidOutput, sprintf(
                        '%s produced "%s", which is outside its directory "%s".',
                        $generator::class,
                        $file->path,
                        $directory,
                    ));

                    continue;
                }

                if (isset($files[$file->path])) {
                    $problems[] = new GenerationProblem(GenerateErrorCode::InvalidOutput, sprintf(
                        'More than one generator produced "%s".',
                        $file->path,
                    ));

                    continue;
                }

                $files[$file->path] = $file;
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        ksort($files, SORT_STRING);
        sort($directories, SORT_STRING);

        return new GenerationResult(
            array_values($files),
            $directories,
        );
    }
}
