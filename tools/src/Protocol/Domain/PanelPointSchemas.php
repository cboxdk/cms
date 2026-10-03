<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Protocol\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\PhpCodecEmitter;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\LiteralPrinter;
use Cbox\Cms\Generators\Codec\Domain\TypeScriptEmitter;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\Dto\ValueBinding;
use Cbox\Cms\Tooling\Protocol\Domain\Dto\PointSchema;

/**
 * The props of the panel's points (GUARDRAILS 2.2, 2.4, PRD 13.4): one JSON Schema per point and
 * version in SCHEMA_DIRECTORY, `<name>.v<version>.json`, bound to the point's props class, the
 * class that carries its #[PanelPoint]. A point is a contract with addons, so its props reach a
 * contribution only as the JSON its generated codec writes, and an addon types and checks them
 * with the generated TypeScript; types go one way, from PHP and the schema to TypeScript.
 *
 * composer generate:protocol writes, from these bindings, the PHP codec of each point into
 * PHP_DIRECTORY and, into TYPESCRIPT_DIRECTORY, the validators' runtime module and a module per
 * point, `points/<Props class>.ts`, with the props' types, their validator and the sample props
 * made from the schema; and it writes the compatibility lock, LOCK, with the contract of every
 * #[Stable] point, refusing a change to one that is not an added optional member. An #[Internal]
 * point is the core's own wiring: it is never contributed to, so it has no binding and no
 * TypeScript.
 *
 * The bindings live in the repository's tooling, not in the generators module, because the
 * generators may not use the panel. The panel's points come with the pages that render them (X5's
 * RenderedPanelPointsTest holds every declared point to a page), and each brings its binding here;
 * until a page renders one, the panel declares none.
 */
final readonly class PanelPointSchemas
{
    /** Where the schemas are, relative to the root of cboxdk/cms. */
    public const string SCHEMA_DIRECTORY = 'packages/panel/resources/schemas/points';

    /** Where the codecs go, relative to the root of cboxdk/cms; composer generate:protocol owns it. */
    public const string PHP_DIRECTORY = 'packages/panel/src/Boundary/Generated/Points';

    public const string PHP_NAMESPACE = 'Cbox\Cms\Panel\Boundary\Generated\Points';

    /** Where the TypeScript goes, relative to the root of cboxdk/cms; composer generate:protocol owns it. */
    public const string TYPESCRIPT_DIRECTORY = 'js/panel-sdk/src/generated';

    /** The runtime module of the validators, below the TypeScript directory. */
    public const string RUNTIME = 'validation.ts';

    /** The directory of the points' modules, below the TypeScript directory. */
    public const string POINTS = 'points';

    /** The compatibility lock, relative to the root of cboxdk/cms. */
    public const string LOCK = 'packages/panel/resources/points.lock.json';

    /** The stability of the generated codecs: the panel and addon tests read and write points' props through them. */
    public const string ATTRIBUTE = Experimental::class;

    /**
     * The binding of each point's schema, sorted by file.
     *
     * @return list<SchemaBinding>
     */
    public static function all(): array
    {
        return [];
    }

    /**
     * The binding of the schema of a point's props at $version, in $directory: $schema is
     * `<name>.v<version>.json`, and $codecClass the props class's short name with `Codec` before
     * its version, such as AccountMeSectionsCodecV1 for AccountMeSectionsV1.
     *
     * @param  positive-int  $version
     * @param  array<string, string>  $objects
     * @param  array<string, ValueBinding>  $values
     * @param  array<string, string>  $names
     */
    public static function point(string $schema, string $codecClass, int $version, array $objects, array $values = [], array $names = [], string $directory = self::SCHEMA_DIRECTORY): SchemaBinding
    {
        return new SchemaBinding(
            schema: $schema,
            codecClass: $codecClass,
            version: $version,
            objects: $objects,
            values: $values,
            names: $names,
            directory: $directory,
        );
    }

    /**
     * The codec of each point in the PHP location, and in $typescript the runtime module and each
     * point's module, which the result owns.
     *
     * @param  list<PointSchema>  $points
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput or GenerateErrorCode::NameCollision
     */
    public static function result(array $points, string $runtime, PhpLocation $php, string $typescript, string $schemas = self::SCHEMA_DIRECTORY): GenerationResult
    {
        $files = [$typescript.'/'.self::RUNTIME => new GeneratedFile($typescript.'/'.self::RUNTIME, $runtime)];

        foreach ($points as $point) {
            $contract = $point->contract;
            $codec = PhpCodecEmitter::emit($contract, $php, $php);
            $name = (string) preg_replace('/Codec(V[0-9]+)\z/', '$1', $contract->codecClass);
            $module = TypeScriptEmitter::emit(
                $contract,
                $typescript.'/'.self::POINTS.'/'.$name.'.ts',
                '../validation',
                [
                    sprintf('The props of the panel point %s, %s, as TypeScript (GUARDRAILS 2.2, PRD 13.4):', $point->point->toString(), $name),
                    sprintf('their JSON form, which the panel\'s codec %s writes, a validator that checks a', $contract->codecClass),
                    'JSON value against every rule of the point\'s JSON Schema, and sample props made from it.',
                    '',
                    'Generated by composer generate:protocol from the schemas in '.$schemas.'.',
                    'Do not edit this file: change the schema and run composer generate:protocol.',
                ],
            );
            $module = new GeneratedFile($module->path, $module->contents.implode("\n", self::sample($name, $point))."\n");

            foreach ([$codec, $module] as $file) {
                if (isset($files[$file->path])) {
                    throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('Two point schemas give the file %s; give each point and version its own props class.', $file->path));
                }

                $files[$file->path] = $file;
            }
        }

        ksort($files, SORT_STRING);

        return new GenerationResult(array_values($files), [$typescript, $php->directory]);
    }

    /**
     * The function that gives the point's sample props, a fresh object on each call.
     *
     * @return list<string>
     */
    private static function sample(string $name, PointSchema $point): array
    {
        $head = '  return ';

        return [
            '',
            '/**',
            sprintf(' * Sample props of %s, made from its JSON Schema: every member present, each with', $point->point->toString()),
            ' * its first example, its first allowed value or the least value its rules accept.',
            ' */',
            'export function sample'.$name.'(): '.$name.' {',
            ...explode("\n", $head.LiteralPrinter::print($point->sample, strlen($head), 2, 1).';'),
            '}',
        ];
    }
}
