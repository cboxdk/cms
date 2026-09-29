<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Subscriptions\Adapter\RegistryLaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Domain\UnusableSubscriber;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\RecordingSubscriber;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\SubscriberJournal;
use Illuminate\Container\Container;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * LaneSubscribersBehaviour against the compiled registry the application reads, with the recording
 * subscriber built by a container.
 */
final class RegistryLaneSubscribersBehaviourTest extends TestCase
{
    use LaneSubscribersBehaviour;

    #[Override]
    protected function laneSubscribers(): LaneSubscribers
    {
        $container = new Container;
        $container->instance(SubscriberJournal::class, new SubscriberJournal);

        return new RegistryLaneSubscribers(new CompiledRegistry([], [], [], [
            RecordingSubscriber::entry('test.critical'),
            RecordingSubscriber::entry('test.standard', Lane::Standard),
        ]), $container);
    }

    #[Test]
    public function it_refuses_a_registered_class_the_container_does_not_build_as_a_subscriber(): void
    {
        $container = new Container;
        $container->instance(RecordingSubscriber::class, new stdClass);
        $subscribers = new RegistryLaneSubscribers(new CompiledRegistry([], [], [], [RecordingSubscriber::entry()]), $container);

        $this->expectException(UnusableSubscriber::class);
        $this->expectExceptionMessage('names '.RecordingSubscriber::class.', which the container does not build as a Cbox\Cms\Contracts\Subscribers\Subscriber. Run cms:build.');

        $subscribers->in(Lane::Critical);
    }
}
