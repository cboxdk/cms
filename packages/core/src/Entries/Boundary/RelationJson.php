<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Core\Pipeline\Boundary\FieldValuesInput;
use stdClass;

/**
 * The attributes a relation's edge carries in `relations.meta` (PRD 5.11), written here alone: the
 * attributes of the edge's target as a JSON object by handle, each value in the input form the
 * validator reads (FieldValuesInput), or null for an edge without attributes.
 */
#[Internal]
final readonly class RelationJson
{
    public static function meta(?FieldMap $attributes): ?string
    {
        if (! $attributes instanceof FieldMap) {
            return null;
        }

        $object = new stdClass;

        foreach ($attributes->fields as $attribute) {
            $object->{$attribute->handle->value} = FieldValuesInput::value($attribute->value);
        }

        return json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
