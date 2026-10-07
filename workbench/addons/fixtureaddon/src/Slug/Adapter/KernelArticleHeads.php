<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Slug\Adapter;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use Override;
use Workbench\FixtureAddon\Slug\Domain\ArticleHeads;
use Workbench\FixtureAddon\Slug\Domain\Dto\ArticleHead;

/**
 * ArticleHeads over the kernel's readers: the entry with the head of its shared variant through
 * EntryReader, the type through the TypeCatalog, and the fields of the head's revision through
 * RevisionContents, each on the command transaction's connection under its actor context, so row
 * level security decides what the command sees. A head whose content the kernel cannot read as
 * the type is now, written under another schema version, is given as absent, because the command
 * cannot then write a whole snapshot.
 *
 * EntryReader and RevisionContents are #[Internal]: the kernel has no stable contract yet for an
 * addon's write action to read the head of a variant it revises, so this adapter reaches them
 * behind the addon's own port with explicit waivers, the one place the addon does, until the
 * entry editor's work gives addons a contract for it (PRD 5.4).
 */
final readonly class KernelArticleHeads implements ArticleHeads
{
    public function __construct(
        private EntryReader $entries, // @phpstan-ignore cboxCms.internalUse, cboxCms.internalUse (no stable contract reads a variant's head for an addon yet; until the entry editor's work gives one)
        private RevisionContents $revisions, // @phpstan-ignore cboxCms.internalUse, cboxCms.internalUse (no stable contract reads a revision's content for an addon yet; until the entry editor's work gives one)
        private TypeCatalog $types,
    ) {}

    #[Override]
    public function head(EntryId $entry): ?ArticleHead
    {
        $shared = VariantKey::shared();
        $stored = $this->entries->entry($entry, $shared); // @phpstan-ignore cboxCms.internalUse (the same reach as the constructor's)
        $head = $stored?->head;

        if (! $stored instanceof StoredEntry || ! $head instanceof StoredHead) { // @phpstan-ignore cboxCms.internalUse, cboxCms.internalUse (the same reach as the constructor's)
            return null;
        }

        $type = $this->types->find($stored->type);

        if (! $type instanceof TypeDefinition) {
            return null;
        }

        $fields = $this->revisions->find($entry, $shared, $head->revision, $type)?->fields; // @phpstan-ignore cboxCms.internalUse (the same reach as the constructor's)

        if (! $fields instanceof FieldValues) {
            return null;
        }

        return new ArticleHead($stored->type, $stored->home, $head->version, $head->revision, $head->latest, $fields);
    }
}
