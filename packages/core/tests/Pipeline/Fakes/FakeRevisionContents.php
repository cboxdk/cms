<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RevisionContent;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use Override;

/**
 * The revisions a test puts on it, in memory, read as the Postgres adapter reads them: the schema
 * version a revision was written under, and its fields when that version is the type's.
 * RevisionContentsBehaviour holds it to PostgresRevisionContents.
 */
final class FakeRevisionContents implements RevisionContents
{
    /** @var array<string, array{int, FieldValues}> by entry, variant and number */
    private array $revisions = [];

    public function with(EntryId $entry, VariantKey $variant, RevisionNumber $revision, int $schemaVersion, FieldValues $fields): self
    {
        $this->revisions[$this->key($entry, $variant, $revision)] = [$schemaVersion, $fields];

        return $this;
    }

    #[Override]
    public function find(EntryId $entry, VariantKey $variant, RevisionNumber $revision, TypeDefinition $type): ?RevisionContent
    {
        $stored = $this->revisions[$this->key($entry, $variant, $revision)] ?? null;

        if ($stored === null) {
            return null;
        }

        [$schemaVersion, $fields] = $stored;

        return new RevisionContent($schemaVersion, $schemaVersion === $type->version ? $fields : null);
    }

    private function key(EntryId $entry, VariantKey $variant, RevisionNumber $revision): string
    {
        return $entry->toString().':'.$variant->value.':'.$revision->value;
    }
}
