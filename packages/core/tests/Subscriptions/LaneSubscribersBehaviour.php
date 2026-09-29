<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\SubscriberBinding;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\RecordingSubscriber;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every LaneSubscribers does, run against RegistryLaneSubscribers and FakeLaneSubscribers, so
 * the fake the runner's tests use cannot drift from the registry the application reads
 * (GUARDRAILS 9).
 */
trait LaneSubscribersBehaviour
{
    /**
     * The implementation under test, knowing the recording subscriber as test.critical on the
     * critical lane and as test.standard on the standard lane, in that order.
     */
    abstract protected function laneSubscribers(): LaneSubscribers;

    #[Test]
    public function it_gives_the_subscribers_of_a_lane_with_their_entries(): void
    {
        $critical = $this->laneSubscribers()->in(Lane::Critical);

        Assert::assertSame(['test.critical'], array_map(static fn (SubscriberBinding $binding): string => $binding->entry->name->value, $critical));
        Assert::assertInstanceOf(RecordingSubscriber::class, $critical[0]->subscriber);
        Assert::assertSame(['test.standard'], array_map(static fn (SubscriberBinding $binding): string => $binding->entry->name->value, $this->laneSubscribers()->in(Lane::Standard)));
        Assert::assertSame([], $this->laneSubscribers()->in(Lane::External));
    }

    #[Test]
    public function it_finds_a_subscription_by_name_in_any_lane(): void
    {
        $entry = $this->laneSubscribers()->named(new SubscriptionName('test.standard'));

        Assert::assertInstanceOf(SubscriberEntry::class, $entry);
        Assert::assertSame(Lane::Standard, $entry->lane);
        Assert::assertNull($this->laneSubscribers()->named(new SubscriptionName('test.gone')));
    }
}
