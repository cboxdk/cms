<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use stdClass;

/**
 * The input of a contribution's data query, taken from the point's props by name (PRD 13.4): the
 * members of the props, as the point's codec wrote them for the contribution, whose keys the
 * query's JSON Schema names as properties, as a JSON document the query's codec then reads.
 * cms:build checks that the props give every required input of the right type
 * (registry_panel_data_query_invalid); a member the props withheld above the addon's reads is not
 * there to take, so the query's codec refuses an input that requires it.
 */
#[Internal]
final readonly class QueryInput
{
    /**
     * @throws DecodingFailed when the props or the schema are not JSON objects
     */
    public static function of(JsonDocument $props, JsonSchema $schema): string
    {
        $members = JsonText::decode($props->value);
        $properties = JsonText::decode($schema->json)->properties ?? null;
        $input = new stdClass;

        if ($properties instanceof stdClass) {
            foreach (array_keys(get_object_vars($properties)) as $key) {
                if (property_exists($members, $key)) {
                    $input->{$key} = $members->{$key};
                }
            }
        }

        return JsonText::encode($input);
    }
}
