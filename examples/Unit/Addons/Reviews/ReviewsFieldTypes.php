<?php

declare(strict_types=1);

namespace Examples\Unit\Addons\Reviews;

use Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor;

/**
 * The field type contributor the reviews addon's manifest names: the field types it lists,
 * reviews:stars.
 */
final readonly class ReviewsFieldTypes implements FieldTypeContributor
{
    public function fieldTypes(): array
    {
        return [new StarsFieldType];
    }
}
