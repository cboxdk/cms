<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The types of the catalog a seeder can write, sorted by name, and why each other type cannot be
 * seeded.
 */
#[Internal]
final readonly class SeedCatalog
{
    /**
     * @param  list<SeedableType>  $types
     * @param  list<string>  $skipped  one sentence per type left out
     */
    public function __construct(
        public array $types,
        public array $skipped,
    ) {}
}
