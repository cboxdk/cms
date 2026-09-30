<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\FieldTypes\Fixtures;

use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\FieldTypes\FieldShape;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContribution;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeOptions;
use Cbox\Cms\Contracts\FieldTypes\IntegerShape;
use Override;

/**
 * The fixture addon's field type `acme:stars`: a rating from 1 to `max` stars (default 5), stored
 * and typed as an integer.
 */
final readonly class StarsFieldType implements FieldTypeContribution
{
    public const string NAME = 'acme:stars';

    public const int DEFAULT_MAX = 5;

    #[Override]
    public function name(): ContributedFieldType
    {
        return new ContributedFieldType(self::NAME);
    }

    #[Override]
    public function optionsSchema(): string
    {
        return __DIR__.'/stars.options.json';
    }

    #[Override]
    public function shape(FieldTypeOptions $options): FieldShape
    {
        return new IntegerShape(1, $options->integer('max') ?? self::DEFAULT_MAX, 'stars');
    }
}
