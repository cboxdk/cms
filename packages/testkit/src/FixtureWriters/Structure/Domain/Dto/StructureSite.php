<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;

/**
 * A site the structure fixtures wrote: its id, its root node and the locales it publishes in.
 */
#[Experimental]
final readonly class StructureSite
{
    /**
     * @param  list<Locale>  $locales
     */
    public function __construct(
        public SiteId $id,
        public string $handle,
        public StructureNode $root,
        public array $locales,
    ) {}
}
