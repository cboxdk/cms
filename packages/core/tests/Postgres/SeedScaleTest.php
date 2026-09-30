<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Cbox\Cms\Core\Seeding\Domain\EntryGenerator;
use Cbox\Cms\Core\Seeding\Domain\SeedProfile;
use Cbox\Cms\Core\Seeding\Domain\SeedProfiles;
use Cbox\Cms\Core\Tests\Seeding\SeedWorld;
use Cbox\Cms\Core\TypeTables\Boundary\TypeTableColumns;
use Cbox\Cms\Testkit\Postgres\Infrastructure\OwnerTruncation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * cms:seed-scale's action on Postgres (GUARDRAILS 4.1, 4.3, PRD 23): the small profile seeds
 * entries of the workbench's types through the kernel, one changeset of seed.entries per chunk on
 * the bulk stream, each step of a chunk written at once. A second run with the same seed into
 * emptied tables gives the same rows and ids; every entry is stored as entry.create and
 * variant.release store it; and a chunk costs the same statements whatever its size.
 */

afterEach(function (): void {
    SeedWorld::cleanUp();
});

it('seeds 2,000 entries with the small profile deterministically', function (): void {
    $report = new SeedWorld()->seed(SeedProfiles::small(), 7, 2_000);
    $rows = SeedWorld::rows();
    $entries = SeedWorld::entries();

    expect($report->operation->state)->toBe(OperationState::Completed)
        ->and($report->operation->completedNames())->toHaveCount(20)
        ->and($rows['entries'])->toBe(2_000)
        ->and($rows['changesets'])->toBe(20)
        ->and($rows['app__fixture_article'] + $rows['app__fixture_measurement'])->toBeGreaterThanOrEqual(2_000)
        ->and($entries)->toHaveCount(2_000)
        ->and(StorageTables::superuser()->table('events')->where('stream', 'bulk')->count())->toBe($rows['events']);

    new OwnerTruncation(DB::connection('pgsql_owner'))->truncate();

    new SeedWorld()->seed(SeedProfiles::small(), 7, 2_000);

    expect(SeedWorld::rows())->toBe($rows)
        ->and(SeedWorld::entries())->toBe($entries);
});

it('stores each seeded entry as entry.create and variant.release store it', function (): void {
    $report = new SeedWorld()->seed(SeedProfiles::small(), 11, 300);
    $scope = $report->scope;
    $generator = new EntryGenerator(SeedProfiles::small(), 11, $scope->catalog->types, $scope->nodes, $scope->access->classificationAccess);
    $superuser = StorageTables::superuser();
    $released = 0;

    for ($index = 0; $index < 300; $index++) {
        $entry = $generator->entry($index);
        $type = array_find($scope->catalog->types, static fn ($each): bool => $each->definition->id->toString() === $entry->type->toString())?->definition;
        $id = $entry->entry->toString();
        $head = $superuser->table('variant_heads')->where('entry_id', $id)->first();
        $table = $superuser->table((string) $type?->name->table())->where('cms_entry_id', $id);
        $stages = $table->pluck('cms_stage')->all();
        $stored = $superuser->table('entries')->where('id', $id)->first();

        expect($type)->not->toBeNull()
            ->and($stored?->home_node_id)->toBe($entry->home->toString())
            ->and($head?->version)->toBe(1);

        if ($type?->capabilities->stages === Stages::None) {
            expect($stages)->toBe(['released'])
                ->and($superuser->table('head_snapshots')->where('entry_id', $id)->value('rev_no'))->toBe(1);
        } elseif ($entry->release) {
            $released++;
            $published = $superuser->table('revisions')->where('revision_id', $head?->published_revision_id)->first();
            $payloads = $superuser->table('revision_payloads')->whereIn('revision_id', [$head?->draft_revision_id, $head?->published_revision_id])->pluck('content')->all();

            expect($head?->release_state)->toBe('released')
                ->and([$published?->kind, $published?->rev_no])->toBe(['published', 2])
                ->and($payloads)->toHaveCount(2)
                ->and($payloads[0])->toBe($payloads[1])
                ->and($stages)->toBe(['released'])
                ->and($superuser->table('release_log')->where('entry_id', $id)->count())->toBe(1);
        } else {
            expect($head?->release_state)->toBe('unreleased')
                ->and($stages)->toBe(['draft']);
        }

        if ($type !== null) {
            $stored = TypeTableColumns::decode($type, (array) $superuser->table($type->name->table())->where('cms_entry_id', $id)->first());

            foreach (array_filter($type->fields, static fn (FieldDefinition $field): bool => ! $field->encrypted) as $field) {
                $expected = $entry->fields->own->get($field->handle) ?? new NullValue;

                expect($stored->own->get($field->handle)?->equals($expected))->toBeTrue(sprintf('%s of %s', $field->address(), $id));
            }
        }
    }

    expect($released)->toBeGreaterThan(100)
        ->and($superuser->table('events')->count())->toBe(300 * 2 + $released);
});

it('costs the same statements for a chunk of 20 entries and a chunk of 200 (GUARDRAILS 4.1)', function (): void {
    $statements = static function (int $entries): int {
        $profile = new SeedProfile('budget', 1, $entries, 1.0, 1.1, 1.0, 90, 80, '2026-01-01', 3650, 3.0);
        $world = new SeedWorld;
        $count = 0;

        DB::listen(static function (QueryExecuted $query) use (&$count): void {
            $count++;
        });

        $world->seed($profile, 3, $entries);

        return $count;
    };

    $small = $statements(20);
    new OwnerTruncation(DB::connection('pgsql_owner'))->truncate();
    $large = $statements(200);

    expect($large)->toBe($small);
});
