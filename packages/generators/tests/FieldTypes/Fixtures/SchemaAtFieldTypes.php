<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\FieldTypes\Fixtures;

use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\FieldTypes\FieldShape;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContribution;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeOptions;
use Cbox\Cms\Contracts\FieldTypes\IntegerShape;
use Override;

/**
 * The fixture addon's contributor with acme:stars pointing at the options schema a test gives it,
 * such as one that is missing or not a JSON Schema, and acme:grade as it is.
 */
final readonly class SchemaAtFieldTypes implements FieldTypeContribution, FieldTypeContributor
{
    public function __construct(private string $schema) {}

    #[Override]
    public function fieldTypes(): array
    {
        return [$this, new GradeFieldType];
    }

    #[Override]
    public function name(): ContributedFieldType
    {
        return new ContributedFieldType(StarsFieldType::NAME);
    }

    #[Override]
    public function optionsSchema(): string
    {
        return $this->schema;
    }

    #[Override]
    public function shape(FieldTypeOptions $options): FieldShape
    {
        return new IntegerShape(1, 5);
    }
}
