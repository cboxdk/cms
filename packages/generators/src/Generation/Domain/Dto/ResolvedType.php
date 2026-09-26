<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;

/**
 * A type with every top-level field it has once the extensions of all schema roots are applied:
 * its owner's fields and the extension fields of each extender, sorted by name.
 */
#[Internal]
final readonly class ResolvedType
{
    /**
     * @param  list<ResolvedField>  $fields  sorted by name, each name once
     */
    public function __construct(
        public TypeBlueprint $blueprint,
        public array $fields,
    ) {}

    public function handle(): string
    {
        return $this->blueprint->handle->value;
    }
}
