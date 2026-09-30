<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Exceptions\SchemaException;
use stdClass;
use Throwable;

/**
 * The JSON Schemas of the `options` of addons' field types (PRD 13.1, 11.12), each a local file an
 * addon names by its absolute path (FieldTypeContribution::optionsSchema()), read with LocalFile and
 * checked with opis/json-schema's CompliantValidator, as the blueprint files are checked against
 * blueprint.v1.json. A schema that cannot be used is the configuration's problem,
 * generate_invalid_config; options that break a usable schema are generate_schema_invalid at the
 * JSON pointer of each value in its blueprint file.
 */
#[Internal]
final readonly class OptionsSchemas
{
    /** The most violations reported for one field's options. */
    public const int MAX_ERRORS = 20;

    /**
     * The decoded schema, with JSON objects as stdClass as the validator reads them.
     *
     * @param  string  $fieldType  the field type the schema belongs to, as the problem names it
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig when the path is not absolute,
     *                          or the file is missing or not a JSON object
     */
    public function load(string $fieldType, string $path): stdClass
    {
        if (! str_starts_with($path, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            throw $this->unusable($fieldType, $path, 'is not an absolute path. Build it from __DIR__');
        }

        $contents = LocalFile::contents($path);

        if ($contents === null) {
            throw $this->unusable($fieldType, $path, 'does not exist or cannot be read');
        }

        try {
            $schema = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw $this->unusable($fieldType, $path, 'is not valid JSON: '.$invalid->getMessage(), $invalid);
        }

        if (! $schema instanceof stdClass) {
            throw $this->unusable($fieldType, $path, 'is not a JSON object');
        }

        return $schema;
    }

    /**
     * The violations of the options, each named by the options' place and the JSON pointer of the
     * value below it.
     *
     * @return list<GenerationProblem>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig when the schema cannot be used
     */
    public function check(string $fieldType, string $path, stdClass $options, SourceLocation $at): array
    {
        $schema = $this->load($fieldType, $path);
        $validator = new CompliantValidator;
        $validator->setMaxErrors(self::MAX_ERRORS);

        try {
            $error = $validator->validate($options, $schema)->error();
        } catch (SchemaException $invalid) {
            throw $this->unusable($fieldType, $path, 'is not a JSON Schema the validator can use: '.$invalid->getMessage(), $invalid);
        }

        if (! $error instanceof ValidationError) {
            return [];
        }

        $problems = [];

        foreach (new ErrorFormatter()->format($error, true) as $pointer => $messages) {
            $place = new SourceLocation($at->file, $at->pointer.(is_string($pointer) && $pointer !== '/' ? $pointer : ''));

            foreach (is_array($messages) ? $messages : [$messages] as $message) {
                $problems[] = new GenerationProblem(GenerateErrorCode::SchemaInvalid, sprintf(
                    '%s: %s (the options schema of the field type %s)',
                    $place->describe(),
                    is_string($message) ? $message : 'is not valid',
                    $fieldType,
                ));
            }
        }

        return $problems;
    }

    private function unusable(string $fieldType, string $path, string $what, ?Throwable $previous = null): GenerationFailed
    {
        return GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
            'The options schema %s of the field type %s %s. The addon that contributes the field type names it in FieldTypeContribution::optionsSchema().',
            $path,
            $fieldType,
            $what,
        ), $previous);
    }
}
