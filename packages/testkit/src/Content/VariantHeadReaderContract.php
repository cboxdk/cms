<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Content;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantHead;
use Cbox\Cms\Contracts\Content\VariantHeadReader;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for VariantHeadReader (GUARDRAILS 2.3 and 9). The FakeVariantHeadReader
 * and the kernel's reader on Postgres run the same cases, so a test of an addon's write action
 * that reads a head through the fake sees what the kernel reads.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return from
 * variantHeadReader() a reader that holds the heads given, each of the suite's type, suiteType(),
 * homed on the node HOME; a reader on a real database writes each head's entry, head and
 * revisions first, and runs under a context whose regions reach HOME. A head given with null
 * fields is one whose draft revision was written under another schema version than the type's:
 * a real database stores it under the type's version plus one with no fields.
 *
 *     final class FakeVariantHeadReaderContractTest extends TestCase
 *     {
 *         use VariantHeadReaderContract;
 *
 *         protected function variantHeadReader(VariantHead ...$heads): VariantHeadReader
 *         {
 *             return new FakeVariantHeadReader()->with(...$heads);
 *         }
 *     }
 */
#[Experimental]
trait VariantHeadReaderContract
{
    /** The type of every head of the suite, `suite:note`. */
    public const string SUITE_TYPE = 'suite:note';

    public const string HOME = '0192a0c0-0000-7000-8000-0000000000d1';

    public const string ENTRY = '0192a0c0-0000-7000-8000-0000000000e1';

    public const string OTHER_ENTRY = '0192a0c0-0000-7000-8000-0000000000e2';

    /**
     * The reader under test, holding the heads given.
     */
    abstract protected function variantHeadReader(VariantHead ...$heads): VariantHeadReader;

    /**
     * The suite's type: a text field label and an integer field rank, full history.
     */
    public static function suiteType(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString('0192a0c0-0000-7000-8000-0000000000f1'),
            new TypeName(self::SUITE_TYPE),
            2,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, true),
            [],
            [
                new FieldDefinition(null, new FieldHandle('label'), 'text', ClassificationAccess::Public, true, false, true, false, false, new ColumnDefinition('label', 'text', true, [])),
                new FieldDefinition(null, new FieldHandle('rank'), 'integer', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('rank', 'bigint', false, [])),
            ],
        );
    }

    /**
     * The fields of a head of the suite's type.
     */
    public static function fields(string $label, int $rank): FieldValues
    {
        return new FieldValues(new FieldMap(new NamedValue(new FieldHandle('label'), new TextValue($label)), new NamedValue(new FieldHandle('rank'), new IntegerValue($rank))));
    }

    /**
     * A head of the suite's type on ENTRY, or the entry given.
     */
    public static function suiteHead(VariantKey $variant, int $version, int $revision, int $latest, ?int $released, ReleaseState $state, ?FieldValues $fields, string $entry = self::ENTRY): VariantHead
    {
        return new VariantHead(
            EntryId::fromString($entry),
            self::suiteType()->id,
            NodeId::fromString(self::HOME),
            $variant,
            new AggregateVersion($version),
            new RevisionNumber($revision),
            new RevisionNumber($latest),
            $released === null ? null : new RevisionNumber($released),
            $state,
            $fields,
        );
    }

    #[Test]
    public function it_reads_the_head_of_a_variant_with_its_numbers_its_state_and_the_fields_of_its_draft(): void
    {
        $head = self::suiteHead(VariantKey::shared(), 5, 4, 4, null, ReleaseState::Unreleased, self::fields('A note', 2));

        Assert::assertEquals($head, $this->variantHeadReader($head)->head(EntryId::fromString(self::ENTRY), VariantKey::shared()));
    }

    #[Test]
    public function it_reads_the_released_revision_and_the_state_of_a_released_and_of_a_withdrawn_head(): void
    {
        $released = self::suiteHead(VariantKey::shared(), 6, 4, 5, 5, ReleaseState::Released, self::fields('Released', 1));
        $withdrawn = self::suiteHead(VariantKey::shared(), 7, 4, 5, null, ReleaseState::Withdrawn, self::fields('Withdrawn', 1), self::OTHER_ENTRY);
        $reader = $this->variantHeadReader($released, $withdrawn);

        Assert::assertEquals($released, $reader->head(EntryId::fromString(self::ENTRY), VariantKey::shared()));
        Assert::assertEquals($withdrawn, $reader->head(EntryId::fromString(self::OTHER_ENTRY), VariantKey::shared()));
    }

    #[Test]
    public function it_reads_each_variant_s_head_apart_and_none_for_a_variant_the_entry_has_no_head_of(): void
    {
        $shared = self::suiteHead(VariantKey::shared(), 2, 1, 1, null, ReleaseState::Unreleased, self::fields('Shared', 1));
        $danish = self::suiteHead(VariantKey::of(new Locale('da')), 3, 2, 2, null, ReleaseState::Unreleased, self::fields('Dansk', 2));
        $reader = $this->variantHeadReader($shared, $danish);

        Assert::assertEquals($shared, $reader->head(EntryId::fromString(self::ENTRY), VariantKey::shared()));
        Assert::assertEquals($danish, $reader->head(EntryId::fromString(self::ENTRY), VariantKey::of(new Locale('da'))));
        Assert::assertNull($reader->head(EntryId::fromString(self::ENTRY), VariantKey::of(new Locale('en'))));
    }

    #[Test]
    public function it_reads_nothing_for_an_entry_it_does_not_know(): void
    {
        $reader = $this->variantHeadReader(self::suiteHead(VariantKey::shared(), 1, 1, 1, null, ReleaseState::Unreleased, self::fields('Known', 1)));

        Assert::assertNull($reader->head(EntryId::fromString(self::OTHER_ENTRY), VariantKey::shared()));
    }

    #[Test]
    public function it_gives_no_fields_for_a_head_whose_draft_was_written_under_another_schema_version(): void
    {
        $head = self::suiteHead(VariantKey::shared(), 4, 3, 3, null, ReleaseState::Unreleased, null);
        $read = $this->variantHeadReader($head)->head(EntryId::fromString(self::ENTRY), VariantKey::shared());

        Assert::assertEquals($head, $read);
        Assert::assertNull($read?->fields);
        Assert::assertEquals(new RevisionNumber(3), $read?->revision);
    }
}
