<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use LogicException;

/**
 * Entries of the workbench's types for a content list (PRD 5.4, 13.4), written as the superuser
 * with their heads and payloads, as the kernel stores them: ARTICLES entries of the article type
 * homed on a section, whose ids rise with their number, so the newest is the last; the RELEASED
 * newest of them released, the rest drafts; and one entry of the event type homed on a root, whose
 * history keeps a head snapshot, written before the articles. The measurement and notice types
 * have no entry, so their lists are empty. The caller covers the partitions the rows need.
 */
final class ListedContent
{
    /** The articles, more than a page of the panel's content list holds, so the list has a second page. */
    public const int ARTICLES = 27;

    /** How many of the newest articles are released. */
    public const int RELEASED = 2;

    public const string ARTICLE = 'app:fixture_article';

    public const string EVENT = 'app:fixture_event';

    public const string MEASUREMENT = 'app:fixture_measurement';

    public const string EVENT_NAME = 'Harbour festival';

    public const string CREATED_AT = '2026-03-10 12:00:00+00';

    /**
     * Writes the entries, the event homed on $root and the articles on $section.
     */
    public static function seed(TypeCatalog $catalog, string $root, string $section): void
    {
        $superuser = StorageTables::superuser();
        $superuser->table('changeset_register')->insertOrIgnore(['changeset_id' => StorageTables::CHANGESET, 'retention_class' => 'standard']);

        $event = self::type($catalog, self::EVENT);
        $eventId = self::id(0);
        $superuser->table('entries')->insert(self::entry($eventId, $event, $root));
        $superuser->table('variant_heads')->insert([
            'entry_id' => $eventId, 'variant' => 'shared', 'draft_revision_id' => null, 'published_revision_id' => null,
            'schema_version' => $event->version, 'release_state' => 'unreleased', 'version' => 1, 'created_at' => self::CREATED_AT,
        ]);
        $superuser->table('head_snapshots')->insert([
            'entry_id' => $eventId, 'variant' => 'shared', 'rev_no' => 1, 'schema_version' => $event->version, 'format_version' => StoredContent::FORMAT_VERSION,
            'content' => StoredContent::payload(new FieldValues(new FieldMap(new NamedValue(new FieldHandle('fixture_name'), new TextValue(self::EVENT_NAME))))), 'updated_at' => self::CREATED_AT,
        ]);

        $article = self::type($catalog, self::ARTICLE);
        $revision = 9000;

        for ($n = 1; $n <= self::ARTICLES; $n++) {
            $id = self::id($n);
            $released = $n > self::ARTICLES - self::RELEASED;
            $draft = $revision++;
            $superuser->table('entries')->insert(self::entry($id, $article, $section));
            $superuser->table('revisions')->insert([
                'revision_id' => $draft, 'entry_id' => $id, 'variant' => 'shared', 'rev_no' => 1, 'kind' => 'draft',
                'schema_version' => $article->version, 'changeset_id' => StorageTables::CHANGESET, 'created_at' => self::CREATED_AT,
            ]);
            $superuser->table('revision_payloads')->insert([
                'revision_id' => $draft, 'kind' => 'draft', 'format_version' => StoredContent::FORMAT_VERSION, 'content' => StoredContent::payload(EntryFields::article(self::title($n))),
            ]);
            $published = null;

            if ($released) {
                $published = $revision++;
                $superuser->table('revisions')->insert([
                    'revision_id' => $published, 'entry_id' => $id, 'variant' => 'shared', 'rev_no' => 2, 'kind' => 'published',
                    'schema_version' => $article->version, 'changeset_id' => StorageTables::CHANGESET, 'created_at' => self::CREATED_AT,
                ]);
            }

            $superuser->table('variant_heads')->insert([
                'entry_id' => $id, 'variant' => 'shared', 'draft_revision_id' => $draft, 'published_revision_id' => $published,
                'schema_version' => $article->version, 'release_state' => $released ? 'released' : 'unreleased', 'version' => $released ? 2 : 1, 'created_at' => self::CREATED_AT,
            ]);

            if ($published !== null) {
                $superuser->table('release_log')->insert([
                    'entry_id' => $id, 'variant' => 'shared', 'action' => 'released', 'revision_id' => $published,
                    'effective_at' => self::CREATED_AT, 'changeset_id' => StorageTables::CHANGESET,
                ]);
            }
        }
    }

    /**
     * The title of the article with the number, from 1 for the oldest.
     */
    public static function title(int $n): string
    {
        return sprintf('Field report %02d', $n);
    }

    /**
     * The id of the entry with the number: the event is 0 and the articles 1 to ARTICLES, so a
     * higher number is a newer entry.
     */
    public static function id(int $n): string
    {
        return sprintf('0192a0c0-0000-7000-8000-%012x', 0xC0DE00 + $n);
    }

    private static function type(TypeCatalog $catalog, string $name): TypeDefinition
    {
        return $catalog->named(new TypeName($name)) ?? throw new LogicException(sprintf('The workbench has no type %s.', $name));
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function entry(string $id, TypeDefinition $type, string $home): array
    {
        return ['id' => $id, 'type_id' => $type->id->toString(), 'home_node_id' => $home, 'owner_actor_id' => null, 'lifecycle' => 'active', 'version' => 1, 'created_at' => self::CREATED_AT];
    }
}
