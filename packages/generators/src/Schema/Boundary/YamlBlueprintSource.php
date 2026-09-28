<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\BlueprintRules;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Override;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the blueprint files below schema roots with symfony/yaml and validates each one against
 * the blueprint schema v1 with opis/json-schema (PRD 11.12, blueprint decision 5).
 *
 * Every `*.yaml` file below a root, at any depth, is a blueprint file (BlueprintFiles); the files
 * of all roots are read in sorted order of the path that problems name. A file is parsed with
 * PARSE_OBJECT_FOR_MAP, so a mapping stays an object and an empty mapping differs from an empty
 * list, and without custom tags or PHP objects, so `!tag` and a duplicate key fail with their line. The document is then
 * validated with CompliantValidator, which follows the specification and never writes a default
 * into the data, against blueprint.v1.json in the installed cboxdk/cms.
 *
 * A file whose `blueprint` marker is above 1 is not validated but reported as
 * generate_schema_unsupported_version, and so is a value that the installed schema allows and this
 * generator cannot map. Every validation error is generate_schema_invalid with the file and the
 * JSON pointer. The blueprints that were read are then held to BlueprintRules, the rules that
 * compare values within a file and across files, each with its own code. read() fails after the
 * last file with the problems of all of them. Both packages are suggested by cboxdk/cms, not
 * required, so read() first checks that they are installed (SuggestedPackages).
 */
#[Internal]
final readonly class YamlBlueprintSource implements BlueprintSource
{
    /** The most validation errors reported for one file. */
    public const int MAX_ERRORS_PER_FILE = 100;

    public function __construct(
        private BlueprintSchemaFile $schema,
        private BlueprintDocumentReader $documents,
        private BlueprintRules $rules,
    ) {}

    #[Override]
    public function read(array $roots): Blueprints
    {
        SuggestedPackages::require(SuggestedPackages::BLUEPRINT_READER, 'Reading the blueprint files');

        $overlapping = BlueprintFiles::overlapping($roots);

        if ($overlapping !== []) {
            throw GenerationFailed::with($overlapping);
        }

        $problems = [];
        $files = [];

        foreach ($roots as $root) {
            array_push($files, ...BlueprintFiles::below($root, $problems));
        }

        usort($files, static fn (array $a, array $b): int => [$a['file'], $a['root']->owner->value, $a['path']] <=> [$b['file'], $b['root']->owner->value, $b['path']]);

        $types = [];
        $extensions = [];

        if ($files !== []) {
            $schema = $this->schema->load();
            $validator = new CompliantValidator;
            $validator->setMaxErrors(self::MAX_ERRORS_PER_FILE);

            foreach ($files as $file) {
                $blueprint = $this->readFile($file['root'], $file['path'], $file['file'], $schema, $validator, $problems);

                if ($blueprint instanceof TypeBlueprint) {
                    $types[] = $blueprint;
                } elseif ($blueprint instanceof ExtensionBlueprint) {
                    $extensions[] = $blueprint;
                }
            }
        }

        $blueprints = new Blueprints($types, $extensions);
        array_push($problems, ...$this->rules->check($blueprints, $problems === []));

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        return $blueprints;
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private function readFile(SchemaRoot $root, string $path, string $file, stdClass $schema, CompliantValidator $validator, array &$problems): TypeBlueprint|ExtensionBlueprint|null
    {
        $contents = LocalFile::contents($path);

        if ($contents === null) {
            $problems[] = new GenerationProblem(GenerateErrorCode::SchemaMissing, sprintf('%s cannot be read. Check its permissions.', $file));

            return null;
        }

        try {
            $document = Yaml::parse($contents, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $invalid) {
            $invalid->setParsedLine(YamlErrorLine::of($invalid, $contents));
            $problems[] = new GenerationProblem(GenerateErrorCode::SchemaInvalid, sprintf('%s: not valid YAML: %s', $file, $invalid->getMessage()));

            return null;
        }

        $later = BlueprintDocumentReader::laterVersion($document, $file);

        if ($later instanceof GenerationProblem) {
            $problems[] = $later;

            return null;
        }

        $error = $validator->validate($document, $schema)->error();

        if ($error instanceof ValidationError) {
            array_push($problems, ...$this->validationProblems($error, $file));

            return null;
        }

        if (! $document instanceof stdClass) {
            $problems[] = new GenerationProblem(GenerateErrorCode::SchemaInvalid, sprintf('%s, /: is not a mapping.', $file));

            return null;
        }

        try {
            return $this->documents->read($document, $root->owner, $file);
        } catch (GenerationFailed $failed) {
            array_push($problems, ...$failed->problems);

            return null;
        }
    }

    /**
     * One problem per validation error, named by the file and the JSON pointer of the value.
     *
     * @return list<GenerationProblem>
     */
    private function validationProblems(ValidationError $error, string $file): array
    {
        $problems = [];

        foreach (new ErrorFormatter()->format($error, true) as $pointer => $messages) {
            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                $problems[] = new GenerationProblem(GenerateErrorCode::SchemaInvalid, sprintf(
                    '%s, %s: %s',
                    $file,
                    $pointer === '' ? '/' : $pointer,
                    is_string($message) ? $message : 'is not valid',
                ));
            }
        }

        return $problems;
    }
}
