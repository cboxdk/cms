<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use stdClass;

/**
 * Splits the arguments of an MCP tool call into its members (GUARDRAILS 2.2): the arguments must
 * be a JSON object with only the keys of the tool, each of them an object, and each member is
 * handed on as its own JSON text for the generated codec that reads it, the command's, the
 * envelope's or the query's. A refused document throws DecodingFailed, json_malformed for text
 * that is not a JSON object and json_invalid at the path of a member that is missing, is not an
 * object or is not a key of the tool.
 */
#[Internal]
final readonly class ToolArguments
{
    /**
     * The JSON text of each member, by key.
     *
     * @param  list<string>  $keys  the members of the tool's arguments, all of them required
     * @return array<string, string>
     *
     * @throws DecodingFailed
     */
    public static function members(string $arguments, array $keys): array
    {
        $object = JsonValues::object(JsonText::decode($arguments), null, $keys);
        $members = [];

        foreach ($keys as $key) {
            $members[$key] = JsonValues::required($object, $key, null, static fn (mixed $value, FieldPath $at): string => $value instanceof stdClass
                ? JsonText::encode($value)
                : throw DecodingFailed::invalid($at, 'is not an object'));
        }

        return $members;
    }
}
