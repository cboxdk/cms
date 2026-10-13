<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Adapter\TypeRows;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Entries\Domain\LocaleRow;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Illuminate\Database\Connection;

/**
 * Entries of the workbench's type with localization variants, app:fixture_notice, on real
 * Postgres (PRD 10.1, 11.6), written as the superuser the way the entry commands of version 2
 * will store them (T90), because version 1 refuses such a type: the entry, a changeset for its
 * revisions, the head of its shared variant and of each language's variant with a draft revision
 * and, where the variant is released, a published revision, and the type table's rows through
 * TypeRows, one per language with the shared fields in it (LocaleRow), the released row where the
 * language and the shared variant are both released, then the draft. A test of the rebuild and of
 * TypeRows starts from these rows.
 */
final readonly class LocalizedEntries
{
    public const string NOTICE = 'app:fixture_notice';

    public const string CHANGESET = '019cd79e-4600-7000-8000-0000000000c5';

    public const string CREATED_AT = '2026-03-10 12:00:00+00';

    public function __construct(
        public TypeDefinition $type,
        private Connection $db,
    ) {}

    public static function notice(): self
    {
        return new self(EntryWorld::type(self::NOTICE), StorageTables::superuser());
    }

    /**
     * The n-th entry of a test's own.
     */
    public static function entry(int $number): EntryId
    {
        return EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012x', 0x200000 + $number));
    }

    /**
     * The shared variant's fields of a notice: the day it was published and its priority.
     */
    public static function shared(string $publishedOn, int $priority): FieldValues
    {
        return EntryFields::of([
            'fixture_published_on' => new DateValue($publishedOn),
            'fixture_priority' => new IntegerValue($priority),
            'fixture_channel' => new TextValue('fixture_web'),
        ]);
    }

    /**
     * A language's fields of a notice: its title and teaser.
     */
    public static function translated(string $title, ?string $teaser = null): FieldValues
    {
        return EntryFields::of($teaser === null ? ['fixture_title' => new TextValue($title)] : [
            'fixture_title' => new TextValue($title),
            'fixture_teaser' => new TextValue($teaser),
        ]);
    }

    /**
     * Writes the entry with its heads and its type rows.
     *
     * @param  array<string, array{FieldValues, ?FieldValues}>  $locales  each language's draft and, when released, released fields
     */
    public function write(EntryId $entry, NodeId $home, FieldValues $sharedDraft, ?FieldValues $sharedReleased, array $locales): void
    {
        $this->db->table('changeset_register')->insertOrIgnore(['changeset_id' => self::CHANGESET, 'retention_class' => 'standard']);
        $this->db->table('entries')->insert([
            'id' => $entry->toString(),
            'type_id' => $this->type->id->toString(),
            'home_node_id' => $home->toString(),
            'owner_actor_id' => null,
            'lifecycle' => 'active',
            'version' => 1,
            'created_at' => self::CREATED_AT,
        ]);
        $this->head($entry, VariantKey::shared(), $sharedDraft, $sharedReleased);

        foreach ($locales as $locale => [$draft, $released]) {
            $this->head($entry, VariantKey::of(new Locale($locale)), $draft, $released);
        }

        $this->rows($entry, $sharedDraft, $sharedReleased, $locales);
    }

    /**
     * Writes the entry's type rows as the entry commands leave them: for each language, the
     * released row where both it and the shared variant are released, then the draft, which stays
     * only where it differs.
     *
     * @param  array<string, array{FieldValues, ?FieldValues}>  $locales
     */
    public function rows(EntryId $entry, FieldValues $sharedDraft, ?FieldValues $sharedReleased, array $locales): void
    {
        $rows = new TypeRows($this->db);

        foreach ($locales as $locale => [$draft, $released]) {
            $variant = VariantKey::of(new Locale($locale));

            if ($released instanceof FieldValues && $sharedReleased instanceof FieldValues) {
                $rows->release($this->type, $entry, $variant, LocaleRow::of($this->type, $sharedReleased, $released));
            }

            $rows->write($this->type, $entry, $variant, LocaleRow::of($this->type, $sharedDraft, $draft));
        }
    }

    /**
     * The variant's head: revision 1 as the first draft, revision 2 as the published copy of a
     * released variant, and revision 3 as the pending draft when it differs from the release.
     */
    private function head(EntryId $entry, VariantKey $variant, FieldValues $draft, ?FieldValues $released): void
    {
        $first = $this->revision($entry, $variant, 1, 'draft', $released ?? $draft);
        $published = $released instanceof FieldValues ? $this->revision($entry, $variant, 2, 'published', $released) : null;
        $pending = $released instanceof FieldValues && StoredContent::payload($draft) !== StoredContent::payload($released)
            ? $this->revision($entry, $variant, 3, 'draft', $draft)
            : $first;

        $this->db->table('variant_heads')->insert([
            'entry_id' => $entry->toString(),
            'variant' => $variant->value,
            'draft_revision_id' => $pending,
            'published_revision_id' => $published,
            'schema_version' => $this->type->version,
            'release_state' => $published === null ? 'unreleased' : 'released',
            'workflow_state' => null,
            'next_transition_at' => null,
            'version' => 1,
            'created_at' => self::CREATED_AT,
        ]);
    }

    private function revision(EntryId $entry, VariantKey $variant, int $number, string $kind, FieldValues $fields): int
    {
        $id = $this->db->table('revisions')->insertGetId([
            'entry_id' => $entry->toString(),
            'variant' => $variant->value,
            'rev_no' => $number,
            'kind' => $kind,
            'schema_version' => $this->type->version,
            'changeset_id' => self::CHANGESET,
            'created_at' => self::CREATED_AT,
        ], 'revision_id');
        $this->db->table('revision_payloads')->insert([
            'revision_id' => $id,
            'kind' => $kind,
            'format_version' => StoredContent::FORMAT_VERSION,
            'content' => StoredContent::payload($fields),
        ]);

        return $id;
    }
}
