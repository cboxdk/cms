<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema\Fakes;

use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeContributor;
use Override;

/**
 * A contributor other than the core that registers the field types a test gives it, through the
 * same interface as CoreFieldTypes.
 */
final readonly class FakeFieldTypeContributor implements FieldTypeContributor
{
    /** @var list<FieldType> */
    private array $types;

    public function __construct(FieldType ...$types)
    {
        $this->types = array_values($types);
    }

    #[Override]
    public function fieldTypes(): array
    {
        return $this->types;
    }
}
