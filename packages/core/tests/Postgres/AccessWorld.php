<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * A small world for the tests of access (PRD 5.10, 12.2): a tree, two actors with grants, entries
 * homed across the tree and a row in every table the migrations protect with row level security,
 * written as the superuser, and the roles and grants written by the testkit's fixtures as the owner
 * role. The partitions are covered for 2026-03-10, the day of the changeset ids.
 *
 * The tree: the site root ROOT with NEWS, SPORT below NEWS, FOOTBALL below SPORT, CULTURE below the
 * root, and the mount MOUNT of NEWS below the root. ALICE holds the role desk (ceiling internal)
 * allowed on NEWS, denied on SPORT and allowed again on FOOTBALL, and the role legal (ceiling
 * personal) on CULTURE; her credential's ceiling is confidential, and her access internal, the one
 * that holds on every node she reaches. BOB holds desk on SPORT, with a
 * credential ceiling of sensitive, and owns ENTRY_OWNED. The entries are homed on NEWS, SPORT,
 * FOOTBALL, CULTURE and twice on the root: ENTRY_PUBLIC, released with a live placement on NEWS,
 * and ENTRY_OWNED. CHANGESET_ALICE has an internal reason text and CHANGESET_PERSONAL a personal
 * one.
 */
final class AccessWorld
{
    public const string ROOT = '0192a0c0-0000-7000-8000-0000000000a1';

    public const string NEWS = '0192a0c0-0000-7000-8000-0000000000a2';

    public const string SPORT = '0192a0c0-0000-7000-8000-0000000000a3';

    public const string FOOTBALL = '0192a0c0-0000-7000-8000-0000000000a4';

    public const string CULTURE = '0192a0c0-0000-7000-8000-0000000000a5';

    public const string MOUNT = '0192a0c0-0000-7000-8000-0000000000a6';

    public const string SITE = '0192a0c0-0000-7000-8000-0000000000b1';

    public const string ALICE = '0192a0c0-0000-7000-8000-0000000000c1';

    public const string BOB = '0192a0c0-0000-7000-8000-0000000000c2';

    public const string SERVICE = '0192a0c0-0000-7000-8000-0000000000c3';

    public const string CREDENTIAL = '0192a0c0-0000-7000-8000-0000000000c4';

    public const string ENTRY_NEWS = '0192a0c0-0000-7000-8000-0000000000d1';

    public const string ENTRY_SPORT = '0192a0c0-0000-7000-8000-0000000000d2';

    public const string ENTRY_FOOTBALL = '0192a0c0-0000-7000-8000-0000000000d3';

    public const string ENTRY_CULTURE = '0192a0c0-0000-7000-8000-0000000000d4';

    public const string ENTRY_PUBLIC = '0192a0c0-0000-7000-8000-0000000000d5';

    public const string ENTRY_OWNED = '0192a0c0-0000-7000-8000-0000000000d6';

    public const string PLACEMENT_PUBLIC = '0192a0c0-0000-7000-8000-0000000000e1';

    public const string PLACEMENT_DRAFT = '0192a0c0-0000-7000-8000-0000000000e2';

    public const string CHANGESET_ALICE = '019cd79e-4600-7000-8000-0000000000f1';

    public const string CHANGESET_PERSONAL = '019cd79e-4600-7000-8000-0000000000f2';

    public const string CHANGESET_BOB = '019cd79e-4600-7000-8000-0000000000f3';

    /** The read of ALICE's that the read audit holds. */
    public const string READ_ALICE = '019cd79e-4600-7000-8000-0000000000f4';

    public const string TYPE = '0192a0c0-0000-7000-8000-000000000030';

    public const string CREATED_AT = '2026-03-10 12:00:00+00';

    /**
     * The revisions: id => [entry, kind, changeset].
     *
     * @var array<int, array{string, string, string}>
     */
    public const array REVISIONS = [
        1 => [self::ENTRY_NEWS, 'draft', self::CHANGESET_ALICE],
        2 => [self::ENTRY_SPORT, 'draft', self::CHANGESET_BOB],
        3 => [self::ENTRY_FOOTBALL, 'draft', self::CHANGESET_BOB],
        4 => [self::ENTRY_CULTURE, 'draft', self::CHANGESET_PERSONAL],
        5 => [self::ENTRY_PUBLIC, 'draft', self::CHANGESET_BOB],
        6 => [self::ENTRY_PUBLIC, 'published', self::CHANGESET_BOB],
        7 => [self::ENTRY_OWNED, 'draft', self::CHANGESET_BOB],
    ];

    /**
     * The tables the world writes a row to, sorted.
     *
     * @var list<string>
     */
    public const array TABLES = [
        'actors', 'audit', 'changeset_principals', 'changeset_reason_texts', 'changeset_register', 'changesets', 'entries',
        'grants', 'head_snapshots', 'mount_overrides', 'node_routes', 'nodes', 'placement_generations', 'placement_locales',
        'placements', 'read_audit', 'release_log', 'revision_payloads', 'revisions', 'role_permissions', 'roles',
        'service_credential_delegations', 'service_credentials', 'site_locales', 'sites', 'variant_heads',
    ];

    public static function alice(): ActorPrincipal
    {
        return new ActorPrincipal(ActorId::fromString(self::ALICE), [], IssuerKind::Service, ClassificationAccess::Confidential);
    }

    public static function bob(): ActorPrincipal
    {
        return new ActorPrincipal(ActorId::fromString(self::BOB), [], IssuerKind::Service, ClassificationAccess::Sensitive);
    }

    /**
     * The ltree path of a node below the root, by the ids from the root down.
     */
    public static function path(string ...$nodes): string
    {
        return implode('.', array_map(StorageTables::label(...), $nodes));
    }

    public static function seed(): void
    {
        app(PartitionFixtures::class)->cover(new DateTimeImmutable('2026-03-10T00:00:00Z'), new DateTimeImmutable('2026-03-10T23:59:59Z'));

        $superuser = StorageTables::superuser();

        foreach ([
            [self::ROOT, null, 'site', null],
            [self::NEWS, self::ROOT, 'section', null],
            [self::SPORT, self::NEWS, 'section', null],
            [self::FOOTBALL, self::SPORT, 'section', null],
            [self::CULTURE, self::ROOT, 'section', null],
            [self::MOUNT, self::ROOT, 'mount', self::NEWS],
        ] as [$id, $parent, $kind, $source]) {
            $superuser->table('nodes')->insert([
                'id' => $id,
                'parent_id' => $parent,
                'kind' => $kind,
                'path' => self::pathOf($id),
                'mount_source_id' => $source,
                'version' => 1,
                'created_at' => self::CREATED_AT,
            ]);
        }

        $superuser->table('sites')->insert(['id' => self::SITE, 'handle' => 'north', 'root_node_id' => self::ROOT, 'version' => 1, 'created_at' => self::CREATED_AT]);
        $superuser->table('site_locales')->insert(['site_id' => self::SITE, 'locale' => 'da', 'created_at' => self::CREATED_AT]);
        $superuser->table('node_routes')->insert(['site_id' => self::SITE, 'locale' => 'da', 'route' => '/', 'node_id' => self::ROOT, 'created_at' => self::CREATED_AT]);

        foreach ([self::ALICE => 'staff', self::BOB => 'staff', self::SERVICE => 'service'] as $actor => $class) {
            $superuser->table('actors')->insert(['id' => $actor, 'actor_class' => $class, 'state' => 'active', 'version' => 1, 'credential_generation' => 1, 'created_at' => self::CREATED_AT]);
        }

        $superuser->table('service_credentials')->insert([
            'id' => self::CREDENTIAL,
            'actor_id' => self::SERVICE,
            'secret_hash' => str_repeat('ab', 32),
            'credential_generation' => 1,
            'issuer_kind' => 'service',
            'classification_ceiling' => 'internal',
            'expires_at' => '2026-04-10 12:00:00+00',
            'created_at' => self::CREATED_AT,
        ]);
        $superuser->table('service_credential_delegations')->insert(['credential_id' => self::CREDENTIAL, 'position' => 0, 'actor_id' => self::ALICE]);

        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(clock: $clock));
        $desk = $fixtures->role('desk', ClassificationAccess::Internal, [new CommandName('entry.create'), new CommandName('entry.revise')]);
        $legal = $fixtures->role('legal', ClassificationAccess::Personal);
        $alice = ActorId::fromString(self::ALICE);
        $fixtures->grant($alice, $desk, NodeId::fromString(self::NEWS));
        $fixtures->grant($alice, $desk, NodeId::fromString(self::SPORT), GrantEffect::Deny);
        $fixtures->grant($alice, $desk, NodeId::fromString(self::FOOTBALL));
        $fixtures->grant($alice, $legal, NodeId::fromString(self::CULTURE));
        $fixtures->grant(ActorId::fromString(self::BOB), $desk, NodeId::fromString(self::SPORT));

        foreach ([
            self::ENTRY_NEWS => [self::NEWS, null],
            self::ENTRY_SPORT => [self::SPORT, null],
            self::ENTRY_FOOTBALL => [self::FOOTBALL, null],
            self::ENTRY_CULTURE => [self::CULTURE, null],
            self::ENTRY_PUBLIC => [self::ROOT, null],
            self::ENTRY_OWNED => [self::ROOT, self::BOB],
        ] as $entry => [$home, $owner]) {
            $superuser->table('entries')->insert(['id' => $entry, 'type_id' => self::TYPE, 'home_node_id' => $home, 'owner_actor_id' => $owner, 'lifecycle' => 'active', 'version' => 1, 'created_at' => self::CREATED_AT]);
        }

        foreach ([self::CHANGESET_ALICE => self::ALICE, self::CHANGESET_PERSONAL => self::ALICE, self::CHANGESET_BOB => self::BOB] as $changeset => $actor) {
            $superuser->table('changeset_register')->insert(['changeset_id' => $changeset, 'retention_class' => 'standard']);
            $superuser->table('changesets')->insert([
                'changeset_id' => $changeset,
                'command' => 'entry.revise',
                'command_version' => 1,
                'actor_id' => $actor,
                'issuer_kind' => 'human',
                'surface' => 'rest',
                'reason_code' => 'correction',
                'idempotency_key' => 'key-'.$changeset,
                'correlation_id' => 'trace-'.$changeset,
                'format_version' => 1,
                'created_at' => self::CREATED_AT,
            ]);
            $superuser->table('audit')->insert([
                'changeset_id' => $changeset,
                'actor_id' => $actor,
                'command' => 'entry.revise',
                'command_version' => 1,
                'issuer_kind' => 'human',
                'surface' => 'rest',
                'reason_code' => 'correction',
                'legal_basis' => null,
                'aggregates' => '{entry:'.self::ENTRY_NEWS.'}',
                'created_at' => self::CREATED_AT,
            ]);
        }

        $superuser->table('read_audit')->insert([
            'read_id' => self::READ_ALICE,
            'entry_id' => self::ENTRY_CULTURE,
            'actor_id' => self::ALICE,
            'query' => 'entry.find',
            'query_version' => 1,
            'classification' => 'personal',
            'fields' => '{contact}',
            'read_position' => '4827',
            'created_at' => self::CREATED_AT,
        ]);

        $superuser->table('changeset_principals')->insert(['changeset_id' => self::CHANGESET_BOB, 'position' => 1, 'actor_id' => self::ALICE]);
        $superuser->table('changeset_reason_texts')->insert(['changeset_id' => self::CHANGESET_ALICE, 'classification' => 'internal', 'text' => 'Source asked for a correction.', 'created_at' => self::CREATED_AT]);
        $superuser->table('changeset_reason_texts')->insert(['changeset_id' => self::CHANGESET_PERSONAL, 'classification' => 'personal', 'text' => 'Corrected a reader\'s address.', 'created_at' => self::CREATED_AT]);

        foreach (self::REVISIONS as $revision => [$entry, $kind, $changeset]) {
            $superuser->table('revisions')->insert(['revision_id' => $revision, 'entry_id' => $entry, 'variant' => 'shared', 'rev_no' => $revision, 'kind' => $kind, 'schema_version' => 1, 'changeset_id' => $changeset, 'created_at' => self::CREATED_AT]);
            $superuser->table('revision_payloads')->insert(['revision_id' => $revision, 'kind' => $kind, 'format_version' => 1, 'content' => '{}']);
        }

        foreach ([self::ENTRY_NEWS => 1, self::ENTRY_SPORT => 2, self::ENTRY_FOOTBALL => 3, self::ENTRY_CULTURE => 4, self::ENTRY_PUBLIC => 5, self::ENTRY_OWNED => 7] as $entry => $draft) {
            $released = $entry === self::ENTRY_PUBLIC;
            $superuser->table('variant_heads')->insert([
                'entry_id' => $entry,
                'variant' => 'shared',
                'draft_revision_id' => $draft,
                'published_revision_id' => $released ? 6 : null,
                'schema_version' => 1,
                'release_state' => $released ? 'released' : 'unreleased',
                'version' => 1,
                'created_at' => self::CREATED_AT,
            ]);
        }

        foreach ([self::ENTRY_NEWS, self::ENTRY_PUBLIC] as $entry) {
            $superuser->table('head_snapshots')->insert(['entry_id' => $entry, 'variant' => 'shared', 'rev_no' => 1, 'schema_version' => 1, 'format_version' => 1, 'content' => '{}', 'updated_at' => self::CREATED_AT]);
        }

        $superuser->table('release_log')->insert(['entry_id' => self::ENTRY_PUBLIC, 'variant' => 'shared', 'action' => 'released', 'revision_id' => 6, 'effective_at' => self::CREATED_AT, 'changeset_id' => self::CHANGESET_BOB]);

        foreach ([
            [self::PLACEMENT_PUBLIC, self::ENTRY_PUBLIC, 'released', self::NEWS, 'story', 'live', true],
            [self::PLACEMENT_DRAFT, self::ENTRY_NEWS, 'draft', self::CULTURE, 'draft-one', 'hidden', false],
        ] as [$placement, $entry, $stage, $node, $slug, $visibility, $canonical]) {
            $superuser->table('placements')->insert(['id' => $placement, 'entry_id' => $entry, 'version' => 1, 'created_at' => self::CREATED_AT]);
            $superuser->table('placement_generations')->insert(['placement_id' => $placement, 'stage' => $stage, 'node_id' => $node, 'created_at' => self::CREATED_AT]);
            $superuser->table('placement_locales')->insert([
                'placement_id' => $placement,
                'stage' => $stage,
                'locale' => 'da',
                'entry_id' => $entry,
                'node_id' => $node,
                'slug' => $slug,
                'visibility' => $visibility,
                'canonical' => $canonical,
                'created_at' => self::CREATED_AT,
            ]);
        }

        $superuser->table('mount_overrides')->insert(['mount_node_id' => self::MOUNT, 'source_node_id' => self::NEWS, 'entry_id' => self::ENTRY_PUBLIC, 'hidden' => true, 'version' => 1, 'created_at' => self::CREATED_AT]);
    }

    /**
     * A new active service actor with a credential issued on behalf of the actors given, in order,
     * written as the superuser; the credential's token is never used, because the tests resolve
     * the principal the verifier would give. Its grants are the test's.
     *
     * @param  list<string>  $onBehalfOf
     */
    public static function delegate(string $actor, string $credential, array $onBehalfOf): void
    {
        $superuser = StorageTables::superuser();
        $superuser->table('actors')->insert(['id' => $actor, 'actor_class' => 'service', 'state' => 'active', 'version' => 1, 'credential_generation' => 1, 'created_at' => self::CREATED_AT]);
        $superuser->table('service_credentials')->insert([
            'id' => $credential,
            'actor_id' => $actor,
            'secret_hash' => hash('sha256', $credential),
            'credential_generation' => 1,
            'issuer_kind' => 'service',
            'classification_ceiling' => 'sensitive',
            'expires_at' => '2026-04-10 12:00:00+00',
            'created_at' => self::CREATED_AT,
        ]);

        foreach ($onBehalfOf as $position => $person) {
            $superuser->table('service_credential_delegations')->insert(['credential_id' => $credential, 'position' => $position, 'actor_id' => $person]);
        }
    }

    private static function pathOf(string $node): string
    {
        return match ($node) {
            self::ROOT => self::path(self::ROOT),
            self::NEWS => self::path(self::ROOT, self::NEWS),
            self::SPORT => self::path(self::ROOT, self::NEWS, self::SPORT),
            self::FOOTBALL => self::path(self::ROOT, self::NEWS, self::SPORT, self::FOOTBALL),
            self::CULTURE => self::path(self::ROOT, self::CULTURE),
            default => self::path(self::ROOT, $node),
        };
    }
}
