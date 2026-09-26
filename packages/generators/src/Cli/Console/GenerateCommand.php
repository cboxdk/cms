<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Cli\Console;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Actions\GenerateCode;
use Cbox\Cms\Generators\Generation\Boundary\GeneratorConfig;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * `cms:generate`: the type chain from the schema (PRD 11.12, GUARDRAILS 7.1). In milestone 0 it
 * reads the fixture schema in format m0-provisional and writes a PHP enum and a TypeScript union
 * of the type handles. The output is deterministic, so a second run changes nothing, and the gate
 * `composer check:generated` fails when the committed code is not what the schema generates.
 *
 * Exit codes: 0 generated, 65 the schema is invalid, 66 the schema file is missing, 69 symfony/yaml
 * is not installed, 70 a generator produced invalid output, 73 a file could not be written, 78 the
 * configuration is invalid. Each problem is printed with its code, and nothing is written unless
 * generation succeeded.
 */
#[Internal]
#[Description('Generate the typed PHP and TypeScript code from the schema')]
#[Signature('cms:generate')]
final class GenerateCommand extends Command
{
    /** EX_DATAERR from sysexits.h. */
    public const int EXIT_INVALID_SCHEMA = 65;

    /** EX_NOINPUT from sysexits.h. */
    public const int EXIT_SCHEMA_MISSING = 66;

    /** EX_UNAVAILABLE from sysexits.h. */
    public const int EXIT_YAML_UNAVAILABLE = 69;

    /** EX_SOFTWARE from sysexits.h. */
    public const int EXIT_INVALID_OUTPUT = 70;

    /** EX_CANTCREAT from sysexits.h. */
    public const int EXIT_UNWRITABLE = 73;

    /** EX_CONFIG from sysexits.h. */
    public const int EXIT_INVALID_CONFIG = 78;

    public function handle(GenerateCode $generate, Repository $config, Application $app): int
    {
        try {
            $report = $generate->generate(GeneratorConfig::read($config, $app->basePath()));
        } catch (GenerationFailed $failed) {
            foreach ($failed->problems as $problem) {
                $this->error($problem->describe());
            }

            $this->error('Nothing was generated, and the generated code was left as it was.');

            return self::exitCode($failed->problems[0]->code);
        }

        foreach ($report->written as $path) {
            $this->line('written: '.$path);
        }

        foreach ($report->removed as $path) {
            $this->line('removed: '.$path);
        }

        $this->info(sprintf(
            'Generated %d %s: %d written, %d unchanged, %d stale removed.',
            count($report->written) + count($report->unchanged),
            count($report->written) + count($report->unchanged) === 1 ? 'file' : 'files',
            count($report->written),
            count($report->unchanged),
            count($report->removed),
        ));

        return self::SUCCESS;
    }

    public static function exitCode(GenerateErrorCode $code): int
    {
        return match ($code) {
            GenerateErrorCode::InvalidConfig => self::EXIT_INVALID_CONFIG,
            GenerateErrorCode::YamlUnavailable => self::EXIT_YAML_UNAVAILABLE,
            GenerateErrorCode::SchemaMissing => self::EXIT_SCHEMA_MISSING,
            GenerateErrorCode::SchemaSyntax,
            GenerateErrorCode::SchemaFormat,
            GenerateErrorCode::SchemaInvalid,
            GenerateErrorCode::DuplicateType,
            GenerateErrorCode::DuplicateField,
            GenerateErrorCode::InvalidCaseName => self::EXIT_INVALID_SCHEMA,
            GenerateErrorCode::InvalidOutput => self::EXIT_INVALID_OUTPUT,
            GenerateErrorCode::OutputUnwritable => self::EXIT_UNWRITABLE,
        };
    }
}
