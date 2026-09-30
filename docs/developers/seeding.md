---
title: Seeding and the scale check
weight: 28
description: How cms:seed-scale builds a skewed data set of any types through the kernel from a versioned profile and a fixed seed, and how composer scale:check measures the listing at a million entries.
---

# Seeding and the scale check

<!-- extension-point: Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries -->

Scale is tested, not assumed (GUARDRAILS 4.3). `cms:seed-scale` builds a data set of entries through the kernel, and `composer scale:check` seeds a database of its own with it and measures the listing against the target of PRD 23. The same generator builds the small data sets the tests use.

## cms:seed-scale

`cms:seed-scale --entries=N [--profile=small] [--seed=1]` seeds N entries. Underscores may group the digits, as in `--entries=1_000_000`. It runs from the console, never in an HTTP request, and prints what it seeded and its wall time.

It writes as the service actor that `cbox-cms.seeding.service_actor` names ([configuration](configuration.md#seeding)). The actor must exist, be of class service and be active. Its grants decide where the data set goes:

- **Nodes.** The entries go to every node the actor's regions reach, except mounts. Node commands come with block B2, so the seeder spreads its entries over the structure that exists.
- **Types.** It seeds every type of the catalog whose required fields the actor may write. A field above the actor's classification access is left out, and so is a field stored encrypted, whose key management comes with block B6. A type that requires such a field is left out, and the command prints why.

The exit codes are:

| Code | When |
|---|---|
| 0 | Done. |
| 64 | Invalid options. |
| 77 | The actor is not active, or reaches no node. |
| 78 | The service actor is not configured, unknown or not a service actor; the setting cannot be read; or no type of the catalog can be seeded. |
| 70 | The kernel rejected a chunk. The command prints the chunk's unit of work and every error. |

## Through the kernel

Every entry goes through the command pipeline, as the kernel's command `seed.entries` version 1.

- **One changeset per chunk.** Each chunk of the profile's chunk size is one call and one changeset. The issuer is the internal issuer `seed`, so the chunk's events go to the bulk stream (PRD 7.5).
- **Idempotent chunks.** The idempotency key is derived from the chunk's unit of work, `seed:<profile>@<version>:<seed>:<chunk>:<entries of the chunk>`. A chunk that runs again replays its first receipt instead of seeding twice.
- **An operation.** The run is an [operation](operations.md) of the kind `cms.seed`, keyed by the profile, the seed and the entries. A run that stopped resumes at its first chunk that did not complete, and a run that completed seeds nothing again.
- **The Clock's time.** Changesets, events and audit rows are written at the Clock's time, so the partitions' runway covers them. The runway of the tables partitioned on a sequence, such as the events, grows with the hourly `cms:partitions:maintain`, as it does for any other write.

The action `seed.entries` composes one plan for the chunk, with the steps of entry.create and variant.release for each entry: the entry, its first revision, the head moved to it, and the release of that revision when the entry is released. The plan has one sub-plan per step, each holding that step for every entry. So an entry's steps keep their order, and the commit writes each step of the whole chunk in a few statements. A chunk costs the same number of statements whatever its size (GUARDRAILS 4.1). The kernel validates a release of a revision that the same plan creates as the plan holds it, at the release stage.

An entry of the chunk that exists already is left as it is, so a larger run with the same seed only adds the rest. The seeder's pipeline has its own authorization and content hash: it allows `seed.entries` only, and no field the actor may not write, and it hashes a chunk's canonical form.

<!-- example: examples/Unit/Seeding/SeedChunkTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;

// cms:seed-scale sends one seed.entries per chunk: each entry with its id, type, home node, the
// fields of its first revision, and whether that revision is released in the same changeset.

it('holds the entries of one chunk, each once', function (): void {
    $home = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000009a1');
    $type = TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000009d1');
    $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('headline'), new TextValue('Harbour at dawn'))));

    $first = new SeededEntry(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000009e1'), $type, $home, $fields, release: true);
    $second = new SeededEntry(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000009e2'), $type, $home, $fields, release: false);
    $chunk = new SeedEntries($first, $second);

    expect($chunk->entries)->toHaveCount(2)
        ->and($chunk->entries[0]->release)->toBeTrue()
        ->and(fn (): SeedEntries => new SeedEntries($first, $first))->toThrow(InvalidArgumentException::class, 'given twice');
});
```

## Profiles and skew

A profile is versioned. The profile, its version and the seed name the data set: entry n depends only on them, n, the seedable types and the nodes. It never depends on how many entries a run seeds or how they are chunked. So two runs with the same seed give the same entries and ids, and a larger run starts with the entries of a smaller one. Any change to a profile's numbers is a new version.

| Profile | Chunk size | Use |
|---|---|---|
| `small` | 100 | Fixtures and tests. |
| `scale` | 200 | The scale data set; a chunk stays well under 2 seconds (PRD 7.4). |

Both profiles have the same skew:

- **Type mix.** A Zipf distribution with exponent 1 over the seedable types sorted by name: the first type is the most frequent.
- **Entries per node.** A Zipf distribution with exponent 1.1 over the nodes sorted by id: a few nodes hold most entries.
- **Field values.** The values follow the rules of each type's generated validator, so they pass the kernel's validation at the write and the release stage. Nothing in the seeder names a type or a field. An optional field holds a value in 80 % of the entries. A choice falls on the first options most often. Numbers crowd towards their low bound. Dates and date-times crowd towards the anchor, 2026-01-01, and reach back ten years. Text and lists vary in length.
- **Release.** 90 % of the entries of a type that can be released (stages draft-release with full history) are released in their first changeset.

Commit times are not skewed: every chunk commits at the Clock's time.

An entry's id is a UUIDv7 whose time is the anchor plus n milliseconds, with random bits from the entry's own seeded randomizer. So ids are unique, rise with n and are the same in every run.

## composer scale:check

`composer scale:check -- [--entries=1000000] [--runs=11] [--profile=scale] [--seed=1] [--sections=40] [--database=cms_scale] [--keep]` checks the M1 exit criterion: a listing under 20 ms in the database at a million entries. It runs `tools/bin/scale-check.php`, which does the following in order:

1. **Machine.** It prints the CPU, the cores, the memory and the load, and the CPUs and memory Docker gives its containers.
2. **Database.** It recreates the scale database as the owner role, with the set-up of `docker/postgres/sql/database.sql`. It never uses the shared dev database or a test database. With `--keep`, a scale database that exists is kept, so a seed that stopped resumes where it stopped.
3. **Schema.** It migrates the database and runs `cms:partitions:maintain` through `vendor/bin/testbench`.
4. **Structure.** It writes a site with `--sections` sections, and a service actor granted on the site's root, with the testkit's fixture writers.
5. **Seed.** It runs `cms:seed-scale` as that actor, and runs `cms:partitions:maintain` every minute while the seed runs, as the scheduler would. A seed that stops, such as a chunk that a loaded machine held past the command transaction's 5 seconds, runs again, up to three times in all, and resumes its operation at the chunk that did not complete. It prints the seed's wall time.
6. **Listing.** It runs ANALYZE. Then it runs the workbench listing `--runs` times with `EXPLAIN (ANALYZE)`: the generated query builder's newest-first keyset page of 20 of the workbench's article type. It measures the first page and the page after it, each as the sum of its statements' execution times, and prints the median of each.

It exits 0 when both medians are at most 20 ms, 1 when one is above or a step failed, and 2 on a usage error. Start the services first with `composer services:up`.

The row level security of the kernel's tables checks rows through PL/pgSQL functions, and the actor context forces custom plans for the transaction. Each such function therefore sets `plan_cache_mode = auto` for itself, so its lookups keep their plans. Without that, every row a policy checked was planned again, which made a page of 20 cost several milliseconds and a seed chunk most of a second.
