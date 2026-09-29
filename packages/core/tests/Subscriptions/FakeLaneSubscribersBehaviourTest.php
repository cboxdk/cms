<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakeLaneSubscribers;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\RecordingSubscriber;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\SubscriberJournal;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * LaneSubscribersBehaviour against the fake the runner's tests use.
 */
final class FakeLaneSubscribersBehaviourTest extends TestCase
{
    use LaneSubscribersBehaviour;

    #[Override]
    protected function laneSubscribers(): LaneSubscribers
    {
        return new FakeLaneSubscribers([
            RecordingSubscriber::bound(new SubscriberJournal, 'test.critical'),
            RecordingSubscriber::bound(new SubscriberJournal, 'test.standard', Lane::Standard),
        ]);
    }
}
