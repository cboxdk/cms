<?php

declare(strict_types=1);

namespace Examples\Unit\Addons\Reviews;

use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\FieldTypes\FieldShape;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContribution;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeOptions;
use Cbox\Cms\Contracts\FieldTypes\IntegerShape;

/**
 * The reviews addon's field type reviews:stars: a rating of 1 to `max` stars, 5 unless the field's
 * options say otherwise. Every generator writes a field of it as an integer from 1 to max, and the
 * field keeps the name reviews:stars.
 */
final readonly class StarsFieldType implements FieldTypeContribution
{
    public function name(): ContributedFieldType
    {
        return new ContributedFieldType('reviews:stars');
    }

    public function optionsSchema(): string
    {
        return __DIR__.'/stars.options.json';
    }

    public function shape(FieldTypeOptions $options): FieldShape
    {
        return new IntegerShape(min: 1, max: $options->integer('max') ?? 5, unit: 'stars');
    }
}
