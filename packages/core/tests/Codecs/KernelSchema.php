<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Validates a document against one of the kernel's committed JSON Schemas in
 * packages/contracts/resources/schemas, such as receipt.v1.json, with an independent validator, so
 * the codec generated from the schema is held to what the schema itself says.
 */
final class KernelSchema
{
    public const string DIRECTORY = __DIR__.'/../../../contracts/resources/schemas';

    /**
     * The validation errors of a JSON document, by JSON pointer; empty when it is valid.
     *
     * @return array<string, list<string>>
     */
    public static function errors(string $schema, string $json): array
    {
        $contents = file_get_contents(self::DIRECTORY.'/'.$schema);

        if ($contents === false) {
            throw new RuntimeException('The schema '.$schema.' cannot be read in '.self::DIRECTORY.'.');
        }

        $result = new Validator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), $contents);
        $error = $result->error();

        if (! $error instanceof ValidationError) {
            return [];
        }

        /** @var array<string, list<string>> $errors */
        $errors = new ErrorFormatter()->format($error, true);

        return $errors;
    }
}
