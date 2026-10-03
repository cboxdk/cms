<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Protocol\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
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
 * PHP_DIRECTORY with LIST, the class that lists them for the panel, and, into TYPESCRIPT_DIRECTORY, the validators' runtime module and a module per
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

    /** The directory of the kernel contracts' modules the SDK's host API answers with, below the TypeScript directory. */
    public const string PROTOCOL = 'protocol';

    /**
     * The kernel contracts the SDK's host API answers with (section 3.12 of the panel extension
     * architecture), by codec class: a command an addon issues answers with its receipt and, for a
     * rejection, the problem details.
     *
     * @var list<string>
     */
    public const array PROTOCOL_CODECS = ['ProblemCodecV1', 'ReceiptCodecV1'];

    /**
     * The modules that re-export the types of the stable and of the experimental points, below the
     * TypeScript directory: @cboxdk/cms-panel/extend re-exports the first, with the protocol's
     * types, and @cboxdk/cms-panel/experimental the second (decision D1).
     */
    public const string STABLE = 'stable.ts';

    public const string EXPERIMENTAL = 'experimental.ts';

    /** The class in the PHP directory that lists every point's codec. */
    public const string LIST = 'PanelPointCodecs';

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
     * The codec of each point in the PHP location, and in $typescript the runtime module, each
     * point's module, the module of each kernel contract of PROTOCOL_CODECS among $protocol, and the
     * two modules that re-export their types by stability, which the result owns.
     *
     * @param  list<PointSchema>  $points
     * @param  list<CodecContract>  $protocol  the kernel's contracts; those of PROTOCOL_CODECS get a module
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput or GenerateErrorCode::NameCollision
     */
    public static function result(array $points, string $runtime, PhpLocation $php, string $typescript, string $schemas = self::SCHEMA_DIRECTORY, array $protocol = []): GenerationResult
    {
        $files = [$typescript.'/'.self::RUNTIME => new GeneratedFile($typescript.'/'.self::RUNTIME, $runtime)];
        $stable = [];
        $experimental = [];

        foreach ($protocol as $contract) {
            if (! in_array($contract->codecClass, self::PROTOCOL_CODECS, true)) {
                continue;
            }

            $name = (string) preg_replace('/Codec(V[0-9]+)\z/', '$1', $contract->codecClass);
            $module = TypeScriptEmitter::emit(
                $contract,
                $typescript.'/'.self::PROTOCOL.'/'.$name.'.ts',
                '../validation',
                [
                    sprintf('A contract of the kernel, %s, as TypeScript (GUARDRAILS 2.2): its JSON form, which the', $name),
                    sprintf('kernel\'s codec %s writes, and a validator that checks a JSON value against', $contract->codecClass),
                    'every rule of its JSON Schema. The host API of @cboxdk/cms-panel answers with it.',
                    '',
                    'Generated by composer generate:protocol from the JSON Schemas of cboxdk/cms.',
                    'Do not edit this file: change the schema and run composer generate:protocol.',
                ],
            );
            $files[$module->path] = $module;
            $stable[] = $module;
        }

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

            if ($point->stable) {
                $stable[] = $module;
            } else {
                $experimental[] = $module;
            }

            foreach ([$codec, $module] as $file) {
                if (isset($files[$file->path])) {
                    throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('Two point schemas give the file %s; give each point and version its own props class.', $file->path));
                }

                $files[$file->path] = $file;
            }
        }

        $list = self::codecList($points, $php);
        $files[$list->path] = $list;

        foreach ([self::STABLE => [$stable, 'stable', '@cboxdk/cms-panel/extend'], self::EXPERIMENTAL => [$experimental, 'experimental', '@cboxdk/cms-panel/experimental']] as $file => [$modules, $level, $subpath]) {
            $barrel = self::barrel($typescript.'/'.$file, $typescript, $modules, $level, $subpath);
            $files[$barrel->path] = $barrel;
        }

        ksort($files, SORT_STRING);

        return new GenerationResult(array_values($files), [$typescript, $php->directory]);
    }

    /**
     * The class LIST in the PHP location, whose all() gives the PointCodec of every point, sorted by
     * point id, which the panel's service provider registers under PointCodecs::TAG, so the panel
     * finds the codec it encodes a point's props with for each contribution.
     *
     * @param  list<PointSchema>  $points
     */
    private static function codecList(array $points, PhpLocation $php): GeneratedFile
    {
        usort($points, static fn (PointSchema $a, PointSchema $b): int => [$a->point->name->value, $a->point->version] <=> [$b->point->name->value, $b->point->version]);
        $entries = array_map(
            static fn (PointSchema $point): string => sprintf("            new PointCodec(new PointId(new PointName('%s'), %d), new %s),", $point->point->name->value, $point->point->version, $point->contract->codecClass),
            $points,
        );
        $imports = $entries === []
            ? ['use Cbox\\Cms\\Contracts\\Attributes\\Experimental;', 'use Cbox\\Cms\\Panel\\Contributions\\Domain\\Dto\\PointCodec;']
            : ['use Cbox\\Cms\\Contracts\\Attributes\\Experimental;', 'use Cbox\\Cms\\Contracts\\PanelPoints\\PointId;', 'use Cbox\\Cms\\Contracts\\PanelPoints\\PointName;', 'use Cbox\\Cms\\Panel\\Contributions\\Domain\\Dto\\PointCodec;'];

        return new GeneratedFile($php->directory.'/'.self::LIST.'.php', implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace '.$php->namespace.';',
            '',
            ...$imports,
            '',
            '/**',
            ' * The JSON codec of each panel point\'s props, by the point\'s id (GUARDRAILS 2.2, PRD 13.4), which',
            ' * the panel registers under PointCodecs::TAG and encodes a point\'s props with for each',
            ' * contribution, at the lower of the viewer\'s classification access and the addon\'s reads.',
            ' *',
            ' * Generated by composer generate:protocol from the points\' schemas.',
            ' * Do not edit this file: change the schemas and run composer generate:protocol.',
            ' */',
            '#[Experimental]',
            'final readonly class '.self::LIST,
            '{',
            '    /**',
            '     * @return list<PointCodec>',
            '     */',
            '    public static function all(): array',
            '    {',
            ...($entries === [] ? ['        return [];'] : ['        return [', ...$entries, '        ];']),
            '    }',
            '}',
            '',
        ]));
    }

    /**
     * The module that re-exports every type the modules declare, each name once, from the first
     * module by path that declares it: the props of the points of one stability, and for the stable
     * one the kernel contracts the host API answers with, as the SDK's subpath exports them.
     *
     * @param  list<GeneratedFile>  $modules
     */
    private static function barrel(string $path, string $typescript, array $modules, string $level, string $subpath): GeneratedFile
    {
        usort($modules, static fn (GeneratedFile $a, GeneratedFile $b): int => strcmp($a->path, $b->path));
        $seen = [];
        $exports = [];

        foreach ($modules as $module) {
            preg_match_all('/^export (?:interface|type) ([A-Za-z_$][A-Za-z0-9_$]*)/m', $module->contents, $matches);
            $names = array_values(array_filter($matches[1], static fn (string $name): bool => ! isset($seen[$name])));

            foreach ($names as $name) {
                $seen[$name] = true;
            }

            if ($names === []) {
                continue;
            }

            sort($names, SORT_STRING);
            $specifier = './'.substr($module->path, strlen($typescript) + 1, -strlen('.ts'));
            $exports = [...$exports, ...self::exportTypes($names, $specifier)];
        }

        return new GeneratedFile($path, implode("\n", [
            sprintf('// The types of the %s panel points%s, which', $level, $level === 'stable' ? ' and of the kernel contracts the host API answers with' : ''),
            sprintf('// %s re-exports (decision D1 of the panel extension architecture).', $subpath),
            '//',
            '// Generated by composer generate:protocol from the schemas of the points and the kernel.',
            '// Do not edit this file: change the schemas and run composer generate:protocol.',
            '',
            ...($exports === [] ? ['export {};'] : $exports),
            '',
        ]));
    }

    /**
     * `export type { ... } from '<specifier>';` as Prettier prints it: on one line within the print
     * width, otherwise one name per line.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function exportTypes(array $names, string $specifier): array
    {
        $line = sprintf("export type { %s } from '%s';", implode(', ', $names), $specifier);

        if (strlen($line) <= LiteralPrinter::PRINT_WIDTH) {
            return [$line];
        }

        return ['export type {', ...array_map(static fn (string $name): string => '  '.$name.',', $names), sprintf("} from '%s';", $specifier)];
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
