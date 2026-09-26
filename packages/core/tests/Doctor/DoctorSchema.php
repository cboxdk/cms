<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Validates a document against the committed JSON Schema of `cms:doctor --json`,
 * packages/contracts/resources/schemas/doctor.v1.json, with an independent validator.
 */
final class DoctorSchema
{
    public const string PATH = __DIR__.'/../../../contracts/resources/schemas/doctor.v1.json';

    /**
     * The validation errors of a JSON document, by JSON pointer; empty when it is valid.
     *
     * @return array<string, list<string>>
     */
    public static function errors(string $json): array
    {
        $schema = file_get_contents(self::PATH);

        if ($schema === false) {
            throw new RuntimeException('The doctor schema cannot be read at '.self::PATH.'.');
        }

        $result = new Validator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), $schema);
        $error = $result->error();

        if (! $error instanceof ValidationError) {
            return [];
        }

        /** @var array<string, list<string>> $errors */
        $errors = new ErrorFormatter()->format($error, true);

        return $errors;
    }
}
