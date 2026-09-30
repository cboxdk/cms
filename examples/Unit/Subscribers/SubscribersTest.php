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
