<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema\Fakes;

use Cbox\Cms\Generators\Schema\Domain\AddonFieldType;
use Cbox\Cms\Generators\Schema\Domain\ContributedFieldTypes;
use Override;

/**
 * Registered contributors that provide the addon field types a test names, and no others.
 */
final readonly class FakeContributedFieldTypes implements ContributedFieldTypes
{
    /** @var list<string> */
    private array $types;

    public function __construct(string ...$types)
    {
        $this->types = array_values(array_map(static fn (string $type): string => new AddonFieldType($type)->value, $types));
    }

    #[Override]
    public function provides(AddonFieldType $type): bool
    {
        return in_array($type->value, $this->types, true);
    }
}
