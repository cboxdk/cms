---
title: CDN driver
weight: 40
description: "The CdnDriver contract: purge by surrogate key, soft and hard, split into requests the CDN takes, the testkit's FakeCdnDriver and the shared suite CdnDriverContract with its harness."
---

# CDN driver

<!-- extension-point: Cbox\Cms\Contracts\Cdn\CdnDriver -->
<!-- extension-point: Cbox\Cms\Testkit\Cdn\CdnDriverHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Cdn\CdnDriverContract -->

The edge purges its objects by surrogate key through its own index, independent of the server's [fragment store](fragment-store.md) (PRD 9.3). The surrogate keys are the same `DependencyKey`s, `e-{entry}` and `n-{node}`, so one key purges the content on every site that shows it (PRD 9.4). `Cbox\Cms\Contracts\Cdn\CdnDriver` sends the purges. The real drivers come with full-scale invalidation; until then the kernel and its tests purge through the testkit's fake, and `cbox-cms.contracts` has no default for `CdnDriver`.

## The contract

- `purge(CdnPurge $purge): CdnPurgeResult` purges every key of the purge, in requests of at most `maxKeysPerRequest()` keys in the order given, and returns the purge as applied with the number of requests. When the CDN does not take a request, it throws `CdnUnavailable`; the requests before it may have been applied.
- `supportsSoftPurge(): bool` says whether the CDN can mark objects stale instead of removing them.
- `maxKeysPerRequest(): int` is the most keys the CDN takes in one request, at least 1.

A `CdnPurge` holds the keys, in the order given and each once, at least one, and a `PurgeMode`. `Soft` marks the objects stale, so the edge may serve them with `stale-while-revalidate` while it fetches them again: the purge for a change. `Hard` removes them, so they are never served stale: the purge for a removal and for a hard correction (PRD 8.12 point 3). A driver whose CDN cannot purge softly, as Cloudflare's, applies a `Soft` purge `Hard`; a `Hard` purge is never applied softly. `CdnPurgeResult::$applied` says which mode was applied.

The caller gathers the keys of a changeset into one purge per driver (PRD 8.12 point 5), and the driver splits it into requests. A purge is idempotent, so after `CdnUnavailable` the caller retries the whole purge. Purge credentials live in a worker, scoped to one service or zone, never in the request path (PRD 8.10 point 5), so the purge subscriber runs a driver and a web request never does.

## The fake: FakeCdnDriver

`Cbox\Cms\Testkit\Cdn\FakeCdnDriver` records every request it would send, with the keys and the mode the edge applies. Its constructor sets whether it purges softly and the most keys per request, 256 by default, Fastly's limit. `purgedWith()` gives the mode of the latest purge of a key, and `interrupt()` makes it throw `CdnUnavailable` until `restore()`. An application that wants the fake in its container sets `cbox-cms.contracts.Cbox\Cms\Contracts\Cdn\CdnDriver` to it. This example is in the `Unit` suite:

<!-- example: examples/Unit/Cdn/CdnPurgeTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cdn\CdnPurge;
use Cbox\Cms\Contracts\Cdn\PurgeMode;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Testkit\Cdn\FakeCdnDriver;

// The CDN driver on the testkit's fake. A changeset changed an article and removed another from a
// section. The purge subscriber gathers the keys into one purge per driver: soft for the change,
// hard for the removal (PRD 8.12 points 3 and 5).

it('purges a change softly and a removal hard, each in as few requests as the CDN allows', function (): void {
    $cdn = new FakeCdnDriver(maxKeysPerRequest: 2);
    $changed = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'));
    $removed = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000b'));
    $section = DependencyKey::node(NodeId::fromString('01960000-0000-7000-8000-00000000000c'));

    $soft = $cdn->purge(new CdnPurge([$changed], PurgeMode::Soft));
    $hard = $cdn->purge(new CdnPurge([$removed, $section, $changed], PurgeMode::Hard));

    expect($soft->applied->mode)->toBe(PurgeMode::Soft)
        ->and($hard->requests)->toBe(2)
        ->and(array_map(static fn (CdnPurge $request): array => $request->keyStrings(), $cdn->requests()))->toBe([
            [$changed->toString()],
            [$removed->toString(), $section->toString()],
            [$changed->toString()],
        ])
        ->and($cdn->purgedWith($changed))->toBe(PurgeMode::Hard);
});

it('purges hard on a CDN that cannot purge softly', function (): void {
    $cdn = new FakeCdnDriver(softPurge: false);
    $changed = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'));

    expect($cdn->purge(new CdnPurge([$changed], PurgeMode::Soft))->applied->mode)->toBe(PurgeMode::Hard);
});
```

## Running the shared suite against a driver

Every driver runs the shared suite, the trait `Cbox\Cms\Testkit\Cdn\CdnDriverContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `cdn(): CdnDriverHarness`, which returns a harness for a new driver whose edge has taken no request.

`CdnDriverHarness` is the edge's side: `driver()` is the driver under test, `requests()` lists what the edge took, one `CdnPurge` per request with the mode it applied, and `interrupt()` and `restore()` make the edge refuse every request and take them again. The fake is its own harness. A harness for a real driver records the requests at an endpoint that stands in for the CDN's purge API. The testkit runs the suite against a fake that purges softly with three keys per request, and against one that always purges hard.

The cases cover a purge in one request and split over several in order, the mode applied to a soft and a hard purge, a repeated purge, and a refusing edge.
