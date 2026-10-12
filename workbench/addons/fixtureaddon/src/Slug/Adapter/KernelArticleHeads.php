<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Slug\Adapter;

use Cbox\Cms\Contracts\Content\VariantHead;
use Cbox\Cms\Contracts\Content\VariantHeadReader;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Override;
use Workbench\FixtureAddon\Slug\Domain\ArticleHeads;
use Workbench\FixtureAddon\Slug\Domain\Dto\ArticleHead;

/**
 * ArticleHeads over the kernel's VariantHeadReader contract: the head of the entry's shared
 * variant with the fields of its draft revision, read on the command transaction's connection
 * under its actor context, so row level security decides what the command sees. A head whose
 * content the kernel cannot read as the type is now, written under another schema version, is
 * given as absent, because the command cannot then write a whole snapshot.
 */
final readonly class KernelArticleHeads implements ArticleHeads
{
    public function __construct(private VariantHeadReader $heads) {}

    #[Override]
    public function head(EntryId $entry): ?ArticleHead
    {
        $head = $this->heads->head($entry, VariantKey::shared());

        if (! $head instanceof VariantHead || ! $head->fields instanceof FieldValues) {
            return null;
        }

        return new ArticleHead($head->type, $head->home, $head->version, $head->revision, $head->latest, $head->fields);
    }
}
