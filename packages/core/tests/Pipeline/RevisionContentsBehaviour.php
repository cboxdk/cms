<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RevisionContent;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use Cbox\Cms\Core\Tests\Entries\NoteType;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every RevisionContents does, run against PostgresRevisionContents and FakeRevisionContents,
 * so the fake the release tests use cannot drift from what the pipeline reads on Postgres
 * (GUARDRAILS 9). The type is the test type NoteType, at its schema version 3.
 */
trait RevisionContentsBehaviour
{
    public const string ENTRY = '0192a0c0-0000-7000-8000-0000000003e1';

    public const string TITLE = 'A note on the harbour';

    /** An entry of the note type kept with audit-only history: a head snapshot and no revisions. */
    public const string SNAPSHOT_ENTRY = '0192a0c0-0000-7000-8000-0000000003e2';

    /**
     * The contents under test, knowing the shared variant of ENTRY with revision 1 written under
     * NoteType's schema version 3 with the title TITLE, and revision 2 written under version 2 with
     * the same title, and the shared variant of SNAPSHOT_ENTRY with the head snapshot of its
     * revision 4 written under version 3 with the title TITLE.
     */
    abstract protected function revisionContents(): RevisionContents;

    public static function noteFields(): FieldValues
    {
        return new FieldValues(new FieldMap(new NamedValue(new FieldHandle('title'), new TextValue(self::TITLE))));
    }

    #[Test]
    public function it_reads_the_fields_of_a_revision_written_under_the_type_s_schema_version(): void
    {
        $content = $this->revisionContents()->find(EntryId::fromString(self::ENTRY), VariantKey::shared(), RevisionNumber::first(), NoteType::definition());

        Assert::assertInstanceOf(RevisionContent::class, $content);
        Assert::assertSame(3, $content->schemaVersion);
        Assert::assertTrue($content->fields?->equals(self::noteFields()));
    }

    #[Test]
    public function it_reads_no_fields_of_a_revision_written_under_another_schema_version(): void
    {
        Assert::assertEquals(
            new RevisionContent(2, null),
            $this->revisionContents()->find(EntryId::fromString(self::ENTRY), VariantKey::shared(), new RevisionNumber(2), NoteType::definition()),
        );
    }

    /**
     * The note type kept with audit-only history and no stages: its entries keep a head snapshot.
     */
    public static function auditedNote(): TypeDefinition
    {
        $note = NoteType::definition();

        return new TypeDefinition($note->id, $note->name, $note->version, new TypeCapabilities(History::AuditOnly, Stages::None, Localization::None, false), $note->extensions, $note->fields);
    }

    #[Test]
    public function it_reads_the_head_snapshot_of_a_type_that_keeps_no_revisions_when_its_number_is_the_one_asked_for(): void
    {
        $contents = $this->revisionContents();
        $entry = EntryId::fromString(self::SNAPSHOT_ENTRY);
        $content = $contents->find($entry, VariantKey::shared(), new RevisionNumber(4), self::auditedNote());

        Assert::assertInstanceOf(RevisionContent::class, $content);
        Assert::assertSame(3, $content->schemaVersion);
        Assert::assertTrue($content->fields?->equals(self::noteFields()));
        Assert::assertNull($contents->find($entry, VariantKey::shared(), new RevisionNumber(3), self::auditedNote()));
        Assert::assertNull($contents->find(EntryId::fromString(self::ENTRY), VariantKey::shared(), RevisionNumber::first(), self::auditedNote()));
    }

    #[Test]
    public function it_reads_nothing_for_a_revision_the_variant_does_not_have(): void
    {
        $contents = $this->revisionContents();

        Assert::assertNull($contents->find(EntryId::fromString(self::ENTRY), VariantKey::shared(), new RevisionNumber(3), NoteType::definition()));
        Assert::assertNull($contents->find(EntryId::fromString(self::ENTRY), VariantKey::of(new Locale('da')), RevisionNumber::first(), NoteType::definition()));
        Assert::assertNull($contents->find(EntryId::fromString('0192a0c0-0000-7000-8000-0000000003e9'), VariantKey::shared(), RevisionNumber::first(), NoteType::definition()));
    }
}
