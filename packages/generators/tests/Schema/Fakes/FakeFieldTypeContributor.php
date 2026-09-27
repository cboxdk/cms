<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema\Fakes;

use Cbox\Cms\Generators\Schema\Domain\FieldType;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeContributor;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Override;

/**
 * A contributor other than the core that registers the field types a test gives it, in the
 * namespace of the owner it is given, through the same interface as CoreFieldTypes. A test may give
 * it no owner, or names outside the owner's namespace, to see the registry refuse them.
 */
final readonly class FakeFieldTypeContributor implements FieldTypeContributor
{
    /** @var list<FieldType> */
    private array $types;

    public function __construct(private ?Owner $owner, FieldType ...$types)
    {
        $this->types = array_values($types);
    }

    /**
     * A contributor in the namespace `acme` of the contracts' addon fixture.
     */
    public static function acme(FieldType ...$types): self
    {
        return new self(new Owner('acme'), ...$types);
    }

    #[Override]
    public function owner(): ?Owner
    {
        return $this->owner;
    }

    #[Override]
    public function fieldTypes(): array
    {
        return $this->types;
    }
}
