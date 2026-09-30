<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Validation\TypeRules;

/**
 * A type of the catalog the seeder can write: its definition, the rules of its generated validator,
 * which the seeder's values follow, and whether its entries have a revision to release (stages
 * draft-release with full history).
 */
#[Internal]
final readonly class SeedableType
{
    public function __construct(
        public TypeDefinition $definition,
        public TypeRules $rules,
        public bool $releasable,
    ) {}
}
