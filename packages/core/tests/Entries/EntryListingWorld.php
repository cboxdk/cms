<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryDetail;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryHead;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryPlacement;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryPlacementLocale;
use Cbox\Cms\Core\Entries\Domain\Dto\ListedEntry;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Tests\Entries\Fakes\FakeEntryListing;
use Cbox\Cms\Core\Tests\Entries\Fakes\FakeEntryPreviews;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * The world of entry.get and entry.list (PRD 5.4, 5.7, 5.10): two site roots, NORTH with the
 * section NORTH_SECTION below it, and SOUTH; three entries of the workbench's article type, in
 * the order of their ids: FIRST, homed on NORTH_SECTION, unreleased, with its draft revision 1;
 * SECOND, homed on NORTH, released, whose draft is revision 1 and whose release wrote revision 2
 * an hour after the rest with the title "Second, released", placed by PLACEMENT under
 * NORTH_SECTION in da with a live window; and
 * THIRD, homed on SOUTH, unreleased. ADMIN's regions reach NORTH and SOUTH, EDITOR's NORTH alone,
 * and NOBODY reaches nothing. seed() writes it to Postgres as the superuser; listing() gives the
 * fake as a reader's context reads it, and previews() the fake of entry.preview's reads.
 */
final class EntryListingWorld
{
    public const string NORTH = '0192a0c0-0000-7000-8000-0000000004a1';

    public const string NORTH_SECTION = '0192a0c0-0000-7000-8000-0000000004a2';

    public const string SOUTH = '0192a0c0-0000-7000-8000-0000000004a3';

    public const string FIRST = '0192a0c0-0000-7000-8000-0000000004e1';

    public const string SECOND = '0192a0c0-0000-7000-8000-0000000004e2';

    public const string THIRD = '0192a0c0-0000-7000-8000-0000000004e3';

    public const string UNKNOWN = '0192a0c0-0000-7000-8000-0000000004e9';

    public const string PLACEMENT = '0192a0c0-0000-7000-8000-0000000004b1';

    public const string ADMIN = '0192a0c0-0000-7000-8000-0000000004c1';

    public const string EDITOR = '0192a0c0-0000-7000-8000-0000000004c2';

    public const string NOBODY = '0192a0c0-0000-7000-8000-0000000004c3';

    /** The title field of the world's type, the article's, as its editor descriptor names it. */
    public const string TITLE = 'fixture_title';

    public const string CREATED_AT = '2026-03-10 12:00:00+00';

    public const string RELEASED_AT = '2026-03-10 13:00:00+00';

    /**
     * The roots each reader's regions reach.
     *
     * @var array<string, list<string>>
     */
    public const array READERS = [
        self::ADMIN => [self::NORTH, self::SOUTH],
        self::EDITOR => [self::NORTH],
        self::NOBODY => [],
    ];

    /**
     * The ancestors of each node, itself included, from the root down.
     *
     * @var array<string, list<string>>
     */
    public const array ANCESTRY = [
        self::NORTH => [self::NORTH],
        self::NORTH_SECTION => [self::NORTH, self::NORTH_SECTION],
        self::SOUTH => [self::SOUTH],
    ];

    public static function type(): TypeDefinition
    {
        return EntryWorld::type(EntryWorld::ARTICLE);
    }

    public static function at(string $timestamp): DateTimeImmutable
    {
        return new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    }

    /**
     * The context of a reader: a service principal with a sensitive ceiling, regions at the roots
     * the reader reaches and internal classification access.
     */
    public static function context(string $reader): AccessContext
    {
        $roots = self::READERS[$reader] ?? throw new LogicException(sprintf('The entry listing world has no reader %s.', $reader));

        return new AccessContext(
            new ActorPrincipal(ActorId::fromString($reader), [], IssuerKind::Service, ClassificationAccess::Sensitive),
            array_map(static fn (string $root): AccessRegion => new AccessRegion(new NodePath(StorageTables::label($root))), $roots),
            ClassificationAccess::Internal,
        );
    }

    /**
     * Every entry as entry.get gives it, in the order of the ids.
     *
     * @return list<EntryDetail>
     */
    public static function details(): array
    {
        $type = self::type();

        return [
            new EntryDetail(
                EntryId::fromString(self::FIRST),
                $type->id,
                NodeId::fromString(self::NORTH_SECTION),
                EntryLifecycle::Active,
                AggregateVersion::first(),
                [self::head(ReleaseState::Unreleased, 1, 1, null, 1, self::fields('First'))],
                [],
            ),
            new EntryDetail(
                EntryId::fromString(self::SECOND),
                $type->id,
                NodeId::fromString(self::NORTH),
                EntryLifecycle::Active,
                new AggregateVersion(2),
                [self::head(ReleaseState::Released, 1, 2, 2, 3, self::fields('Second'))],
                [new EntryPlacement(PlacementId::fromString(self::PLACEMENT), NodeId::fromString(self::NORTH_SECTION), AggregateVersion::first(), [
                    new EntryPlacementLocale(new Locale('da'), new Slug('anden'), Visibility::Live, new TimeWindow(self::at(self::CREATED_AT)), true),
                ])],
            ),
            new EntryDetail(
                EntryId::fromString(self::THIRD),
                $type->id,
                NodeId::fromString(self::SOUTH),
                EntryLifecycle::Active,
                AggregateVersion::first(),
                [self::head(ReleaseState::Unreleased, 1, 1, null, 1, self::fields('Third'))],
                [],
            ),
        ];
    }

    /**
     * Every entry as entry.list gives it, newest first.
     *
     * @return list<ListedEntry>
     */
    public static function listed(): array
    {
        $listed = [];

        foreach (array_reverse(self::details()) as $detail) {
            $listed[] = self::listedOf($detail);
        }

        return $listed;
    }

    public static function listedOf(EntryDetail $detail): ListedEntry
    {
        return new ListedEntry(
            $detail->id,
            $detail->type,
            $detail->home,
            $detail->lifecycle,
            $detail->heads[0]->state,
            self::at($detail->id->toString() === self::SECOND ? self::RELEASED_AT : self::CREATED_AT),
            $detail->version,
            FakeEntryListing::titleOf($detail, self::TITLE),
        );
    }

    /**
     * The entry with the id, as entry.get gives it.
     */
    public static function detail(string $entry): EntryDetail
    {
        foreach (self::details() as $detail) {
            if ($detail->id->toString() === $entry) {
                return $detail;
            }
        }

        throw new LogicException(sprintf('The entry listing world has no entry %s.', $entry));
    }

    /**
     * The EntryListing in memory as the reader's context reads it.
     */
    public static function listing(string $reader): FakeEntryListing
    {
        $listing = new FakeEntryListing(self::ANCESTRY, array_map(NodeId::fromString(...), self::READERS[$reader] ?? []), [self::type()->id->toString() => self::TITLE]);

        foreach (self::details() as $detail) {
            $listing->add($detail, self::listedOf($detail)->changedAt);
        }

        return $listing;
    }

    /**
     * Writes the world to Postgres as the superuser.
     */
    public static function seed(): void
    {
        $superuser = StorageTables::superuser();
        $type = self::type();

        $superuser->table('nodes')->insert(StorageTables::node(self::NORTH, kind: 'site'));
        $superuser->table('nodes')->insert(StorageTables::node(self::NORTH_SECTION, self::NORTH, StorageTables::label(self::NORTH)));
        $superuser->table('nodes')->insert(StorageTables::node(self::SOUTH, kind: 'site'));
        $superuser->table('changeset_register')->insert(['changeset_id' => StorageTables::CHANGESET, 'retention_class' => 'standard']);

        $revisionId = 400;

        foreach (self::details() as $detail) {
            $head = $detail->heads[0];
            $superuser->table('entries')->insert([
                'id' => $detail->id->toString(), 'type_id' => $type->id->toString(), 'home_node_id' => $detail->home->toString(), 'owner_actor_id' => null,
                'lifecycle' => $detail->lifecycle->value, 'version' => $detail->version->value, 'created_at' => self::CREATED_AT,
            ]);
            $draft = $revisionId++;
            $superuser->table('revisions')->insert([
                'revision_id' => $draft, 'entry_id' => $detail->id->toString(), 'variant' => 'shared', 'rev_no' => $head->revision->value, 'kind' => 'draft',
                'schema_version' => $type->version, 'changeset_id' => StorageTables::CHANGESET, 'created_at' => self::CREATED_AT,
            ]);
            $superuser->table('revision_payloads')->insert([
                'revision_id' => $draft, 'kind' => 'draft', 'format_version' => StoredContent::FORMAT_VERSION, 'content' => StoredContent::payload($head->fields),
            ]);
            $published = null;

            if ($head->released instanceof RevisionNumber) {
                $published = $revisionId++;
                $superuser->table('revisions')->insert([
                    'revision_id' => $published, 'entry_id' => $detail->id->toString(), 'variant' => 'shared', 'rev_no' => $head->released->value, 'kind' => 'published',
                    'schema_version' => $type->version, 'changeset_id' => StorageTables::CHANGESET, 'created_at' => self::RELEASED_AT,
                ]);
                $superuser->table('revision_payloads')->insert([
                    'revision_id' => $published, 'kind' => 'published', 'format_version' => StoredContent::FORMAT_VERSION, 'content' => StoredContent::payload(self::releasedFields($detail)),
                ]);
            }

            $superuser->table('variant_heads')->insert([
                'entry_id' => $detail->id->toString(), 'variant' => 'shared', 'draft_revision_id' => $draft, 'published_revision_id' => $published,
                'schema_version' => $type->version, 'release_state' => $head->state->value, 'version' => $head->version->value, 'created_at' => self::CREATED_AT,
            ]);

            if ($published !== null) {
                $superuser->table('release_log')->insert([
                    'entry_id' => $detail->id->toString(), 'variant' => 'shared', 'action' => 'released', 'revision_id' => $published,
                    'effective_at' => self::RELEASED_AT, 'changeset_id' => StorageTables::CHANGESET,
                ]);
            }

            foreach ($detail->placements as $placement) {
                $superuser->table('placements')->insert(['id' => $placement->id->toString(), 'entry_id' => $detail->id->toString(), 'version' => $placement->version->value, 'created_at' => self::CREATED_AT]);
                $superuser->table('placement_generations')->insert(['placement_id' => $placement->id->toString(), 'stage' => 'released', 'node_id' => $placement->node->toString(), 'created_at' => self::CREATED_AT]);

                foreach ($placement->locales as $locale) {
                    $superuser->table('placement_locales')->insert([
                        'placement_id' => $placement->id->toString(), 'stage' => 'released', 'locale' => $locale->locale->value, 'entry_id' => $detail->id->toString(),
                        'node_id' => $placement->node->toString(), 'slug' => $locale->slug->value, 'visibility' => $locale->visibility->value,
                        'live_from' => $locale->window?->from?->format('Y-m-d H:i:s.uP'), 'live_until' => $locale->window?->until?->format('Y-m-d H:i:s.uP'),
                        'next_transition_at' => null, 'canonical' => $locale->canonical, 'created_at' => self::CREATED_AT,
                    ]);
                }
            }
        }
    }

    /**
     * The fields of the released revision of an entry that has one: its draft's title, released.
     */
    public static function releasedFields(EntryDetail $detail): FieldValues
    {
        return self::fields(match ($detail->id->toString()) {
            self::SECOND => 'Second, released',
            default => throw new LogicException(sprintf('The entry listing world releases no revision of %s.', $detail->id->toString())),
        });
    }

    /**
     * EntryPreviews in memory as the reader's context reads it: the shared head of each entry,
     * with its draft and, for the released entry, its released revision.
     */
    public static function previews(string $reader): FakeEntryPreviews
    {
        $previews = new FakeEntryPreviews(self::ANCESTRY, array_map(NodeId::fromString(...), self::READERS[$reader] ?? []));

        foreach (self::details() as $detail) {
            $head = $detail->heads[0];
            $revisions = [$head->revision->value => $head->fields];

            if ($head->released instanceof RevisionNumber) {
                $revisions[$head->released->value] = self::releasedFields($detail);
            }

            $previews->add($detail->id, $head->variant, $detail->type, $detail->home, $head->revision, $head->released, $head->state, $revisions);
        }

        return $previews;
    }

    private static function head(ReleaseState $state, int $revision, int $latest, ?int $released, int $version, FieldValues $fields): EntryHead
    {
        return new EntryHead(VariantKey::shared(), new AggregateVersion($version), new RevisionNumber($revision), new RevisionNumber($latest), $released === null ? null : new RevisionNumber($released), $state, self::type()->version, $fields);
    }

    private static function fields(string $title): FieldValues
    {
        return EntryFields::article($title);
    }
}
