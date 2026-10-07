<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use stdClass;

/**
 * The names of the properties of a command's JSON Schema (PRD 13.4): the keys of its `properties`
 * object, which the members of the command's document are named by, sorted; none for a schema
 * without properties. CommandBindings matches them with the command's constructor parameters.
 */
#[Internal]
final readonly class SchemaProperties
{
    /**
     * @return list<string>
     *
     * @throws DecodingFailed when the schema is not a JSON object
     */
    public static function of(JsonSchema $schema): array
    {
        $properties = JsonText::decode($schema->json)->properties ?? null;
        $names = $properties instanceof stdClass ? array_keys(get_object_vars($properties)) : [];
        sort($names, SORT_STRING);

        return array_map(strval(...), $names);
    }
}
