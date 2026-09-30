<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Protocol\Adapter;

use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Generation\Adapter\FilesystemGeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Boundary\JsonSchemaContract;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;

/**
 * `composer generate:protocol` (GUARDRAILS 2.2): reads each kernel JSON Schema of
 * ProtocolSchemas::all() below a root, reads it into its codec contract with its binding, emits
 * the codecs and writes them into the core's codecs, removing every other file there. Nothing is
 * written unless every schema is valid; the problems of all schemas are reported together.
 */
final readonly class ProtocolGeneration
{
    /**
     * Runs generate:protocol on the tree below $root, writes what it did to $out and a failure to
     * $err, and gives the exit code: 0, or the catalog's exit code of the first problem.
     *
     * @param  resource  $out
     * @param  resource  $err
     */
    public static function main(string $root, $out, $err): int
    {
        try {
            $report = self::run($root);
        } catch (GenerationFailed $failed) {
            fwrite($err, 'generate:protocol: '.$failed->getMessage()."\n");

            return GenerateCommand::exitCode($failed->problems[0]->code);
        }

        foreach ($report->written as $path) {
            fwrite($out, 'generate:protocol: wrote '.$path."\n");
        }

        foreach ($report->removed as $path) {
            fwrite($out, 'generate:protocol: removed '.$path."\n");
        }

        if (! $report->changed()) {
            fwrite($out, "generate:protocol: the codecs are current.\n");
        }

        return 0;
    }

    /**
     * @throws GenerationFailed
     */
    public static function run(string $root): WriteReport
    {
        $root = rtrim($root, '/');
        $contracts = [];
        $problems = [];

        foreach (ProtocolSchemas::all() as $binding) {
            $path = $binding->path();
            $json = LocalFile::contents($root.'/'.$path);

            if ($json === null) {
                $problems[] = new GenerationProblem(GenerateErrorCode::SchemaMissing, sprintf('The schema %s does not exist or cannot be read.', $path));

                continue;
            }

            try {
                $contracts[] = JsonSchemaContract::read($json, $binding, ProtocolSchemas::ATTRIBUTE);
            } catch (GenerationFailed $failed) {
                array_push($problems, ...$failed->problems);
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        $result = ProtocolSchemas::result($contracts, new PhpLocation(ProtocolSchemas::PHP_DIRECTORY, ProtocolSchemas::PHP_NAMESPACE));

        return new FilesystemGeneratedOutput()->write($root, $result);
    }
}
