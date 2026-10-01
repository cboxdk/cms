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

    /** The schemas of the core's own documents, such as delivery-fragment.v1.json. */
    public const string CORE_DIRECTORY = __DIR__.'/../../resources/schemas';

    /**
     * The validation errors of a JSON document, by JSON pointer; empty when it is valid.
     *
     * @return array<string, list<string>>
     */
    public static function errors(string $schema, string $json, string $directory = self::DIRECTORY): array
    {
        $contents = file_get_contents($directory.'/'.$schema);

        if ($contents === false) {
            throw new RuntimeException('The schema '.$schema.' cannot be read in '.$directory.'.');
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
