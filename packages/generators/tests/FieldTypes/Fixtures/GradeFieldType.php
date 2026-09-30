<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\FieldTypes\Fixtures;

use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\FieldTypes\FieldShape;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContribution;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeOptions;
use Cbox\Cms\Contracts\FieldTypes\SelectChoice;
use Cbox\Cms\Contracts\FieldTypes\SelectShape;
use Override;

/**
 * The fixture addon's field type `acme:grade`: one of the grades its options list, each with its
 * label, stored and typed as a select.
 */
final readonly class GradeFieldType implements FieldTypeContribution
{
    public const string NAME = 'acme:grade';

    #[Override]
    public function name(): ContributedFieldType
    {
        return new ContributedFieldType(self::NAME);
    }

    #[Override]
    public function optionsSchema(): string
    {
        return __DIR__.'/grade.options.json';
    }

    #[Override]
    public function shape(FieldTypeOptions $options): FieldShape
    {
        return new SelectShape(array_map(
            static fn (FieldTypeOptions $grade): SelectChoice => new SelectChoice($grade->string('value') ?? '', $grade->string('label') ?? ''),
            $options->objects('grades') ?? [],
        ));
    }
}
