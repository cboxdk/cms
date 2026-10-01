---
title: Subscribers
weight: 43
description: "React to committed changes: a subscriber class, the #[Subscription] that names its events, lane and projection, and the subscribers registry cms:build compiles, which tells the kernel the projections a receipt lists."
---

# Subscribers

<!-- extension-point: Cbox\Cms\Contracts\Subscribers\Subscriber -->
<!-- extension-point: Cbox\Cms\Contracts\Attributes\Subscription -->

Everything that happens because of a change, after the change has committed, is a subscriber: invalidating fragments, purging a CDN, reindexing search, sending webhooks (PRD 6.2 phase 8, 7.6). A subscriber reads [events](events.md) from the event log, never inside the command's transaction, so nothing it does can make a command fail, and it may do IO, which a hook may not (PRD 6.3).

## A subscriber is a class

A subscriber implements `Cbox\Cms\Contracts\Subscribers\Subscriber`, whose one method is `handle(StoredEvent $event, Delivery $delivery): void`, and carries `#[Subscription]`. It is a `final readonly class` and keeps no state between events (GUARDRAILS 2.1). It works under the rules of the event log (PRD 7.4, 7.7):

- **State-based.** An event means "aggregate X is now at version V, read the state". The payload carries ids and versions, never content, so the subscriber reads what it needs from the state.
- **At least once, not in commit order.** An event can come twice, and an older version can come after a newer one. The subscriber is idempotent per (aggregate, version) and ignores a version older than the last it handled.
- **Never writes content.** To change something it issues a command, as any other issuer does (PRD 8.6). The runner calls `handle()` inside its own batch transaction, and the command pipeline never begins inside an open transaction, so a subscriber cannot yet issue a command from `handle()`.

## The runner

`cms:events:run` hands the events to the subscribers of one lane (PRD 7.4 to 7.8). Run one process per lane; a second process of the same lane is safe, because each batch of a subscription holds the subscription's lock in Postgres, and the second process passes a subscription the first is handling. It runs until `SIGTERM` or `SIGINT` and ends after the batch in progress; `--until-idle` stops once nothing waits. The runner builds the critical lane only, so `--lane` takes `critical`, the default.

- **As a service identity.** The subscribers run as the service actor that `cbox-cms.events.runner.service_actor` names, never as the system (PRD 6.5 invariant 21). Before each round the runner reads that actor, and it refuses to run with [`subscription_identity_invalid`](../reference/errors.md#subscription_identity_invalid) (exit 78) when none is named, the actor does not exist or is not of class service, and with [`actor_not_active`](../reference/errors.md#actor_not_active) (exit 77) once it is not active. An addon's subscriber runs instead as the addon's own service actor, from `cbox-cms.addons.service_actors` ([manifest](manifest.md)); when that actor is not configured, does not exist or is not an active service actor, the runner hands the subscription nothing that round, reports and logs it with [`addon_service_actor_unavailable`](../reference/errors.md#addon_service_actor_unavailable), keeps its cursor and runs the lane's other subscriptions. Each batch runs under the access context of the subscription's actor, set on the connection before the subscriber is called, so row level security holds what the subscriber reads and writes there to that actor's grants. The `Delivery` that `handle()` gets names that actor in `$actor`, with the try in `$attempt`, from 1.
- **Every event, once below the horizon.** A batch reads the events after the subscription's cursor in each stream, only those of transactions that have certainly ended, in (xid, event_id) order, on the primary. So an event whose transaction commits after one with a higher event_id is never skipped; it is read once its transaction has ended. The subscriber gets the types it declared; the runner passes the others and moves the cursor past them.
- **The cursor commits with the subscriber's writes.** A batch is one transaction on the default connection, at READ COMMITTED. What the subscriber writes on that connection commits together with the moved cursor, or neither does. `handle()` never begins, commits or rolls back a transaction. A batch reads at most `batch_size` events and stops handing them after `batch_budget_ms`, so the transaction stays under 2 seconds.
- **Retries with backoff.** When `handle()` throws, the batch rolls back. The runner hands the events before the failed one again at once and tries the failed one again after `backoff_base_ms`, doubled after each failed try up to `backoff_max_ms` (PRD 7.7). The other subscriptions of the lane go on in the meantime.
- **Parking.** After `max_attempts` failed tries the runner parks the aggregate for the subscription and passes the event; the aggregate's later events are parked with it, and every other aggregate keeps flowing (PRD 7.8). `cms:events:parked [subscription]` lists what is parked.
- **Release.** Once the fault is fixed, `cms:events:release <subscription> <type>:<id>` releases the aggregate. The runner then hands the subscriber, once, the newest event of the aggregate that the subscription has passed, the aggregate's current version, with `$delivery->release` set, and removes the parking in the same transaction. A release that fails `max_attempts` tries parks the aggregate again. It refuses a subscription no subscriber has with [`subscription_unknown`](../reference/errors.md#subscription_unknown) and an aggregate that is not parked with [`subscription_not_parked`](../reference/errors.md#subscription_not_parked), both exit 65.

The settings are in [configuration](../developers/configuration.md#event-runner).

## #[Subscription]

`#[Subscription(name, events: [...], lane: ..., projection: ...)]` sits on the subscriber class:

| Argument | Value |
|---|---|
| `name` | The subscription's name, dot-separated snake_case segments of at most 63 characters, such as `acme.search.index` (`SubscriptionName`). The event log keeps the subscription's cursor per stream and its parked aggregates under this name, so it stays when the class is renamed. A name belongs to one subscriber. |
| `events` | The event classes it receives, such as `PagePublished::class`, at least one, each once. Each class exists and implements `Event`. The registry keeps them sorted by class, with the type each gives. |
| `lane` | A case of `Cbox\Cms\Contracts\Subscribers\Lane`, see below. |
| `projection` | The projection it acknowledges on a receipt, such as `fragments` or `acme.search` (`ProjectionName`), or null, the default, when no receipt waits for its work. |

The lanes are those of PRD 7.6. Each has its own workers and lag target, so a slow webhook never delays a purge:

| Lane | Value | For | Lag target |
|---|---|---|---|
| `Lane::Critical` | `critical` | fragment invalidation, purges, removal from the search index on withdrawal and erasure, the mirror of actors' state | p95 under 500 ms |
| `Lane::Standard` | `standard` | search reindexing, feeds, sitemaps, the consistency checker | p95 under 60 s |
| `Lane::External` | `external` | webhooks, partner deliveries, shipping to a SIEM | p95 under 5 min |
| `Lane::Revalidate` | `revalidate` | revalidation webhooks to headless frontends | p95 under 2 s |
| `Lane::Background` | `background` | analytics, the vector index, a customer's replica | best effort |

## Projections on the receipt

A write returns a receipt with the status of each projection its changeset affects (PRD 8.4), and a caller can wait until a projection has acknowledged. The projections an event affects are those its subscribers name in `projection`. The kernel asks the compiled registry, `Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry`, which `cms:build` writes and the container gives:

- `projectionsFor(string $eventClass)` gives the `ProjectionName`s of the subscribers that receive events of the class, each once, sorted by name. A subscriber without a projection adds none, and an event class no subscriber receives affects none.
- `subscribersOf(string $eventClass)` gives the `SubscriberEntry`s that receive it, in registry order.

Class names are compared without case, as PHP compares them.

## The kernel's invalidation subscriber

The kernel has one subscriber of its own, `fragments.invalidate` on the critical lane, which acknowledges the projection `origin`, the one the wait level `origin` waits for (PRD 8.4). It receives the content events `entry.created`, `variant.revised`, `variant.released`, `variant.unreleased`, `placement.created` and `placement.visibility_changed`, so every command that changes what the public sees, publishing and unpublishing included, waits for it at `origin`. For each event:

1. It purges the fragments of the content keys the event affects (PRD 9.4): `e-{entry}`, and for a placement event also `n-{node}` of the node the placement sits under, which every answer of a path below that node depends on, a 404 included. It purges them through the fragment store's purge fence at the event's commit position: for `cbox-cms.fragments.fence_seconds` the store refuses a fragment of the key that a read at or below that position built, because the read may not have seen the change (PRD 8.12 point 1).
2. It purges the same keys at the edge through the configured CDN driver: softly for a change, which may be served stale while it is rebuilt (PRD 8.12 point 3), and hard for a removal, `variant.unreleased` and a `placement.visibility_changed` to anything but live, which may never be served again (point 4). A driver without soft purges applies every purge hard. The fragments go first, so an edge that refetches gets a fresh origin.
3. It acknowledges `origin` on the receipt of the event's changeset, in the runner's batch, so the acknowledgement commits with the cursor.

When the CDN does not take the purge, the batch rolls back and the runner tries the event again, so `origin` is acknowledged only once both purges were taken. Every step is idempotent, and a fence never moves down, so a restarted runner that hands the event again changes nothing more. The critical lane needs a CDN driver in `cbox-cms.contracts` (see [configuration](../developers/configuration.md#contracts)).

## The subscribers registry

`cms:build` finds `#[Subscription]` in the scan roots, as it finds the other declarations (see [build declarations](build-declarations.md)), and writes `bootstrap/cache/cms/subscribers.php`. An entry has these keys, in this order, and the entries are sorted by subscription name:

| Key | Value |
|---|---|
| `addon` | the namespace of the subscriber's addon, or null for a package without a [manifest](manifest.md) |
| `class` | the subscriber class |
| `events` | a list of `class`, `name` and `version`: each event class with the name and payload version of its type, sorted by class |
| `lane` | the value of the lane |
| `name` | the subscription name |
| `package` | the Composer package of the scan root |
| `projection` | the projection name, or null |

A build refuses a subscriber with these codes, next to the codes every declaration shares, and writes nothing:

| Code | Problem |
|---|---|
| `registry_not_final_readonly` | The subscriber is not a `final readonly class`. |
| `registry_not_a_subscriber` | The class carrying `#[Subscription]` does not implement `Subscriber`. |
| `registry_unknown_event` | An event class does not exist, does not implement `Event`, or its `type()` fails. |
| `registry_unknown_lane` | The lane is not a case of `Lane`, such as `'critical'` or `Lane::Urgent`. |
| `registry_duplicate_subscription` | Two subscribers declare the same subscription name. |
| `registry_invalid_attribute` | Another argument is invalid: a name or projection that is not one, no event, or an event listed twice. |

## Example

A search package, `acme/cms-search`, declares its scan root in its service provider:

<!-- example-file: examples/Unit/Subscribers/SearchServiceProvider.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the package acme/cms-search. Its scan root is the directory it lies in,
 * so cms:build registers the subscribers next to it.
 */
final class SearchServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-search', __DIR__)];
    }
}
```

Two events, a page published and a page withdrawn, with an id of the package's own:

<!-- example-file: examples/Unit/Subscribers/PageId.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Ids\Identifier;
use InvalidArgumentException;

/**
 * The id of the search addon's aggregate, a page. As an Identifier it can be carried by an event.
 */
final readonly class PageId implements Identifier
{
    public function __construct(public string $value)
    {
        if (preg_match('/\Apage-[0-9]+\z/', $value) !== 1) {
            throw new InvalidArgumentException('A page id is "page-" and digits.');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}
```

<!-- example-file: examples/Unit/Subscribers/PagePublished.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\EventType;

/**
 * An event the subscribers receive: a page was published, page.published version 1. The payload
 * carries nothing; a subscriber reads the page's state (PRD 7.4).
 */
final readonly class PagePublished implements Event, EventPayload
{
    public function __construct(
        private PageId $page,
        private int $version,
    ) {}

    public static function type(): EventType
    {
        return new EventType('page.published', 1);
    }

    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType('page'), $this->page, $this->version);
    }

    public function payload(): EventPayload
    {
        return $this;
    }

    public function data(): EventData
    {
        return EventData::empty();
    }
}
```

<!-- example-file: examples/Unit/Subscribers/PageWithdrawn.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Events\AggregateType;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventAggregate;
use Cbox\Cms\Contracts\Events\EventData;
use Cbox\Cms\Contracts\Events\EventPayload;
use Cbox\Cms\Contracts\Events\EventType;

/**
 * An event the search subscriber receives: a page was withdrawn, page.withdrawn version 1.
 */
final readonly class PageWithdrawn implements Event, EventPayload
{
    public function __construct(
        private PageId $page,
        private int $version,
    ) {}

    public static function type(): EventType
    {
        return new EventType('page.withdrawn', 1);
    }

    public function aggregate(): EventAggregate
    {
        return new EventAggregate(new AggregateType('page'), $this->page, $this->version);
    }

    public function payload(): EventPayload
    {
        return $this;
    }

    public function data(): EventData
    {
        return EventData::empty();
    }
}
```

The subscriber that indexes a page, on the standard lane, acknowledges the projection `acme.search`. It keeps the index outside itself and ignores a version it has already indexed:

<!-- example-file: examples/Unit/Subscribers/SearchIndex.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

/**
 * The search addon's index, in memory for the example: the version of each page it has indexed.
 * A real index would be a search engine the subscriber calls.
 */
final class SearchIndex
{
    /** @var array<string, int> the indexed version, by page id */
    public array $pages = [];
}
```

<!-- example-file: examples/Unit/Subscribers/IndexPages.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;

/**
 * The search addon's subscriber: it indexes a page when it is published or withdrawn, on the
 * standard lane, and acknowledges the projection acme.search on the receipt. It is state-based:
 * the event says "page X is now at version V", and a version it has already indexed is ignored.
 */
#[Subscription('acme.search.index', events: [PagePublished::class, PageWithdrawn::class], lane: Lane::Standard, projection: 'acme.search')]
final readonly class IndexPages implements Subscriber
{
    public function __construct(private SearchIndex $index) {}

    public function handle(StoredEvent $event, Delivery $delivery): void
    {
        $page = $event->aggregate->id->toString();

        if (($this->index->pages[$page] ?? 0) >= $event->aggregate->version) {
            return;
        }

        $this->index->pages[$page] = $event->aggregate->version;
    }
}
```

The subscriber that tells partners acknowledges no projection, because no receipt waits for a partner:

<!-- example-file: examples/Unit/Subscribers/NotifyPartners.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;

/**
 * The search addon's second subscriber: it tells partners that a page was published, on the
 * external lane. No receipt waits for a partner, so it acknowledges no projection.
 */
#[Subscription('acme.search.partners', events: [PagePublished::class], lane: Lane::External)]
final readonly class NotifyPartners implements Subscriber
{
    public function handle(StoredEvent $event, Delivery $delivery): void {}
}
```

The test builds the registry with `cms:build`, reads `subscribers.php`, where the kernel's own subscriber sorts after the package's two, asks the compiled registry for the projections of each event, and hands the index subscriber a newer and then an older version of a page. It uses the `BuildTestCase` of [build declarations](build-declarations.md):

<!-- example: examples/Unit/Subscribers/SubscribersTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use DateTimeImmutable;
use Examples\Unit\Build\BuildTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * cms:build compiles the search package's subscribers into subscribers.php, and the registry
 * answers which projections an event class affects: the ones a receipt lists for a changeset that
 * writes that event.
 */
final class SubscribersTest extends BuildTestCase
{
    #[Test]
    public function it_compiles_the_subscribers_of_a_package(): void
    {
        self::assertSame(0, $this->build(SearchServiceProvider::class));
        self::assertStringContainsString('subscribers: 3', $this->buildOutput());

        $subscribers = require $this->registryFile('subscribers');
        self::assertIsArray($subscribers);
        self::assertSame('subscribers', $subscribers['registry']);

        // The package's two subscribers, and the kernel's own invalidation subscriber after them.
        $entries = $subscribers['entries'];
        self::assertIsArray($entries);
        self::assertSame(['acme.search.index', 'acme.search.partners', 'fragments.invalidate'], array_column($entries, 'name'));
        self::assertSame([
            [
                'addon' => null,
                'class' => IndexPages::class,
                'events' => [
                    ['class' => PagePublished::class, 'name' => 'page.published', 'version' => 1],
                    ['class' => PageWithdrawn::class, 'name' => 'page.withdrawn', 'version' => 1],
                ],
                'lane' => 'standard',
                'name' => 'acme.search.index',
                'package' => 'acme/cms-search',
                'projection' => 'acme.search',
            ],
            [
                'addon' => null,
                'class' => NotifyPartners::class,
                'events' => [
                    ['class' => PagePublished::class, 'name' => 'page.published', 'version' => 1],
                ],
                'lane' => 'external',
                'name' => 'acme.search.partners',
                'package' => 'acme/cms-search',
                'projection' => null,
            ],
        ], array_slice($entries, 0, 2));
    }

    #[Test]
    public function it_tells_which_projections_an_event_class_affects(): void
    {
        self::assertSame(0, $this->build(SearchServiceProvider::class));

        $registry = app(CompiledRegistry::class);

        self::assertEquals([new ProjectionName('acme.search')], $registry->projectionsFor(PagePublished::class));
        self::assertEquals([new ProjectionName('acme.search')], $registry->projectionsFor(PageWithdrawn::class));
        self::assertCount(2, $registry->subscribersOf(PagePublished::class));
    }

    #[Test]
    public function it_indexes_each_version_of_a_page_once(): void
    {
        $index = new SearchIndex;
        $subscriber = new IndexPages($index);

        $delivery = new Delivery(ActorId::fromString('01960000-0000-7000-8000-00000000000a'));

        $subscriber->handle($this->stored(new PagePublished(new PageId('page-7'), 2), 1), $delivery);
        $subscriber->handle($this->stored(new PagePublished(new PageId('page-7'), 1), 2), $delivery);

        self::assertSame(['page-7' => 2], $index->pages);
    }

    /**
     * The event as the log gives it to a subscriber.
     */
    private function stored(PagePublished $event, int $eventId): StoredEvent
    {
        return new StoredEvent(
            new EventPosition(900, $eventId),
            new DateTimeImmutable('2026-09-29T08:00:00Z'),
            ChangesetId::fromString('01960000-0000-7000-8000-000000000001'),
            EventStream::Interactive,
            1,
            $event->aggregate(),
            PagePublished::type(),
            $event->payload()->data(),
        );
    }
}
```
