<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Articles\Domain\Dto;

use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * One article of fixtureaddon.articles: the entry, the owner's title, and the addon's slug, or
 * null where the addon's field is not set.
 */
final readonly class ArticleSummary
{
    public function __construct(
        public EntryId $entry,
        public string $title,
        public ?string $slug,
    ) {}
}
