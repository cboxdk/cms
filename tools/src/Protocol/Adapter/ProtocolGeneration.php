<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Protocol\Adapter;

use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Generation\Adapter\FilesystemGeneratedOutput;
use Cbox\Cms\Generators\Generation\Boundary\TypeScriptRuntime;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Boundary\JsonSchemaContract;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;
use Cbox\Cms\Tooling\Protocol\Domain\PanelPageSchemas;

/**
 * `composer generate:protocol` (GUARDRAILS 2.2): reads each kernel JSON Schema of
 * ProtocolSchemas::all() below a root, reads it into its codec contract with its binding, emits
 * the codecs and writes them into the core's codecs, removing every other file there. It does the
 * same for the schemas of the panel's pages (PanelPageSchemas), whose codecs go into the panel and
 * whose TypeScript, with the validators' runtime module, goes into js/panel. Nothing is written
 * unless every schema is valid; the problems of all schemas are reported together.
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
        $problems = [];
        $contracts = self::contracts($root, ProtocolSchemas::all(), ProtocolSchemas::ATTRIBUTE, $problems);
        $pages = self::contracts($root, PanelPageSchemas::all(), PanelPageSchemas::ATTRIBUTE, $problems);

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        $kernel = ProtocolSchemas::result($contracts, new PhpLocation(ProtocolSchemas::PHP_DIRECTORY, ProtocolSchemas::PHP_NAMESPACE));
        $panel = PanelPageSchemas::result($pages, new TypeScriptRuntime()->source());
        $files = [...$kernel->files, ...$panel->files];
        usort($files, static fn (GeneratedFile $a, GeneratedFile $b): int => strcmp($a->path, $b->path));
        $directories = [...$kernel->directories, ...$panel->directories];
        sort($directories, SORT_STRING);

        return new FilesystemGeneratedOutput()->write($root, new GenerationResult($files, $directories));
    }

    /**
     * The contract of each schema below $root that a binding names, in order; a missing or invalid
     * schema adds its problems to $problems instead.
     *
     * @param  list<SchemaBinding>  $bindings
     * @param  class-string  $attribute
     * @param  list<GenerationProblem>  $problems
     * @return list<CodecContract>
     */
    private static function contracts(string $root, array $bindings, string $attribute, array &$problems): array
    {
        $contracts = [];

        foreach ($bindings as $binding) {
            $path = $binding->path();
            $json = LocalFile::contents($root.'/'.$path);

            if ($json === null) {
                $problems[] = new GenerationProblem(GenerateErrorCode::SchemaMissing, sprintf('The schema %s does not exist or cannot be read.', $path));

                continue;
            }

            try {
                $contracts[] = JsonSchemaContract::read($json, $binding, $attribute);
            } catch (GenerationFailed $failed) {
                array_push($problems, ...$failed->problems);
            }
        }

        return $contracts;
    }
}
