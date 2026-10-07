<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Slug\Domain;

use Cbox\Cms\Contracts\Ids\EntryId;
use Workbench\FixtureAddon\Slug\Domain\Dto\ArticleHead;

/**
 * Where fixtureaddon.slug.set reads the head of an entry's shared variant from, inside the
 * command's transaction as the actor of the command, so the kernel's policies decide what it
 * sees: the addon's port, bound to its adapter over the kernel's readers.
 */
interface ArticleHeads
{
    /**
     * The head of the entry's shared variant with the fields of its revision, or null when the
     * entry is absent, has no head, or the head's content cannot be read as the type is now.
     */
    public function head(EntryId $entry): ?ArticleHead;
}
