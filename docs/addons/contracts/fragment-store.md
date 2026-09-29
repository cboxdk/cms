---
title: Fragment store
weight: 39
description: "The FragmentStore contract: rendered fragments with their dependency keys, build position and expiry, the reverse index, the purge fence, the Valkey store, the testkit's FakeFragmentStore and the shared suite FragmentStoreContract."
---

# Fragment store

<!-- extension-point: Cbox\Cms\Contracts\Cache\FragmentStore -->
<!-- extension-point: Cbox\Cms\Contracts\Cache\FragmentWriteOutcome -->
<!-- extension-point: Cbox\Cms\Testkit\Cache\FragmentStoreContract -->

The server keeps rendered fragments in Valkey, and beside them the cache index: which fragments depend on which content (PRD 9.3). These are facts about rendered output. They are true only while the output exists and the next render can build them again, so they live in the cache and never in Postgres (PRD 9.1). `Cbox\Cms\Contracts\Cache\FragmentStore` keeps both, so there is never a fragment without its index. The CDN keeps its own index of surrogate keys; that is the [CDN driver](cdn-driver.md).

## Dependency keys and fragments

A `DependencyKey` names content, not a site: `e-{entry}` for an entry and `n-{node}` for a node, the kind's prefix and the id's UUID in lower case (PRD 9.4). One key purges every site that shows the content, through mounts too. `DependencyKey::entry()` and `node()` build one from an id, and `fromString()` reads the form back. The other kinds of PRD 9.4, `a-` for assets, `q-` for query descriptors and `c-` for a page's composition, come with the blocks that bring assets, query descriptors and curation.

A `Fragment` is a final readonly class with five parts:

| Part | Type | What it is |
|---|---|---|
| `key` | `FragmentKey` | 1 to 512 visible ASCII characters, built by the renderer from what the output depends on (PRD 8.10 point 8); the store treats it as opaque |
| `body` | `string` | the rendered bytes |
| `dependencies` | `list<DependencyKey>` | kept sorted and each once; at most 50, the cap on surrogate keys per response (PRD 9.6) |
| `builtAt` | `CommitPosition` | the position of the read the fragment was built from, the xmin of its snapshot: the read saw every changeset below it |
| `validUntil` | `DateTimeImmutable` | kept in UTC; the fragment is gone once the Clock reaches it |

## The contract

- `write(Fragment $fragment): FragmentWriteOutcome` stores the fragment, replacing what the key held and moving it in the reverse index, unless the purge fence refuses it. The outcome is `FragmentStored` or `FragmentFenced`, and no other class. A write whose `validUntil` is not after the Clock's time throws `InvalidCacheValue`.
- `read(FragmentKey $key): ?Fragment` returns the live fragment of the key, or `null`.
- `fragmentsOf(DependencyKey $key): list<FragmentKey>` returns the live fragments that depend on the key, sorted.
- `purge(FragmentPurge $purge): list<FragmentKey>` removes every fragment that depends on the key, takes each out of the index of its other keys, and returns the live ones it removed, sorted.

Time is the `Clock`'s. A store keeps its copies no longer than they are valid, as a TTL, and also compares the Clock's time with the stored instants, so a fragment or a fence ends at the Clock's instant.

## The purge fence

A purge can be undone by a render that read before the change: one that started before the commit, or read a replica that lags (PRD 8.12 point 1). So a `FragmentPurge` carries the commit position of the changeset and the instant its fence ends, and the store keeps `purged:{key} = position` until then. While the fence lives, a write of a fragment that depends on the key and was built at or below that position is refused: it returns `FragmentFenced` with the key, the dependency, the fence's position and the build position, and the store keeps what it held before. A fragment built above the position saw the change and is stored. The check, the write and the index change are one atomic step, so no purge comes between them.

A fence never moves down: a second purge of a key whose fence is higher keeps the higher position and the later end. When a write is fenced, the caller serves what it built to that request only, with `no-store`. Size the fence to outlast the slowest render and the replica lag; a purge whose fence ends at or before the Clock's time throws `InvalidCacheValue`.

A read's position is `pg_snapshot_xmin(pg_current_snapshot())` of the render's transaction, and a changeset's is its `pg_current_xact_id()`; see [the commit position](receipt-store.md#the-commit-position).

This example runs the fence on the fake. It is in the `Unit` suite:

<!-- example: examples/Unit/Cache/FragmentFenceTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentFenced;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentPurge;
use Cbox\Cms\Contracts\Cache\FragmentStored;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Testkit\Cache\FakeFragmentStore;
use Cbox\Cms\Testkit\Clock\FakeClock;

// The fragment store on the testkit's fake. A front page depends on the article it shows. The
// article is revised by the changeset at position 1200, and the purge of its key fences the store:
// a render that read before that commit is refused, one that read after it is kept.

it('refuses a render that read before the purge and keeps one that read after it', function (): void {
    $clock = new FakeClock;
    $store = new FakeFragmentStore($clock);
    $article = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'));
    $front = new FragmentKey('www.example.com/');
    $render = fn (string $readAt): Fragment => new Fragment($front, '<main>...</main>', [$article], new CommitPosition($readAt), $clock->now()->modify('+5 minutes'));

    $store->write($render('1100'));
    $purged = $store->purge(new FragmentPurge($article, new CommitPosition('1200'), $clock->now()->modify('+2 minutes')));

    // A render that started before the commit, or read a replica that lags, arrives late.
    $late = $store->write($render('1150'));
    // The next render read a snapshot whose xmin is past the commit, so it saw the revision.
    $fresh = $store->write($render('1201'));

    expect($purged)->toEqual([$front])
        ->and($late)->toBeInstanceOf(FragmentFenced::class)
        ->and($late instanceof FragmentFenced ? $late->purgedAt->value : null)->toBe('1200')
        ->and($fresh)->toBeInstanceOf(FragmentStored::class)
        ->and($store->read($front)?->builtAt->value)->toBe('1201')
        ->and($store->fragmentsOf($article))->toEqual([$front]);
});

it('forgets a fragment once the clock reaches its validUntil', function (): void {
    $clock = new FakeClock;
    $store = new FakeFragmentStore($clock);
    $article = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'));
    $key = new FragmentKey('www.example.com/news/1');

    $store->write(new Fragment($key, '<article>...</article>', [$article], new CommitPosition('1300'), $clock->now()->modify('+30 seconds')));
    $clock->advance(new DateInterval('PT30S'));

    expect($store->read($key))->toBeNull()
        ->and($store->fragmentsOf($article))->toBe([]);
});
```

## The default: ValkeyFragmentStore

`cbox-cms.contracts` binds `FragmentStore` to `Cbox\Cms\Core\Cache\Adapter\ValkeyFragmentStore` (`packages/core/config/cbox-cms.php`). It uses the default Redis connection, which must be phpredis to one Valkey primary: its scripts touch the fragments the index names, so a cluster is refused. Below the connection's prefix it keeps:

| Key | Type | Holds |
|---|---|---|
| `cms:fragment:<fragment key>` | hash | the fragment, and a field per dependency so a stale index entry is ignored |
| `cms:fragments_of:<dependency key>` | set | the reverse index; it expires no sooner than the last of its fragments |
| `cms:purged:<dependency key>` | hash | the fence's position and end |

`write()` and `purge()` are one Lua script each, so the fence check and the write are one step in Valkey. Positions are compared as decimal strings, because Lua's numbers lose precision above 2^53 and an xid8 reaches 2^64 - 1. If Valkey is lost, fragments and index are lost together; a promoted replica is flushed, because asynchronous replication may have lost the last deletions (PRD 9.3). The core runs the shared suite against it in `packages/core/tests/Contract/ValkeyFragmentStoreContractTest.php`, on the testkit's `RealValkey` harness.

## Running the shared suite against a replacement

`Cbox\Cms\Testkit\Cache\FakeFragmentStore` is the fake: it keeps everything in memory and reads the time from its `Clock`, a `FakeClock` by default. Every implementation runs the shared suite, the trait `Cbox\Cms\Testkit\Cache\FragmentStoreContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `fragmentStore(Clock $clock): FragmentStore`, which returns a new, empty store that reads the time from `$clock`. A store on a server uses the harness for it, such as `RealValkey`, which gives each run its own key prefix.

The cases cover a write and its read back, expiry on the Clock, the reverse index as fragments are written, rewritten and purged, and the fence: refused at and below the purge position, stored above it, compared by numeric value up to the largest xid8, never moving down, and ending with its `fenceUntil`.
