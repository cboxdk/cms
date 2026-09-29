<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\SubscriberBinding;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Cbox\Cms\Core\Subscriptions\Domain\UnusableSubscriber;
use Illuminate\Contracts\Container\Container;
use Override;

/**
 * The subscribers of the compiled registry (PRD 13.2), each built by the container, so a
 * subscriber gets the ports it reads through its constructor.
 */
#[Internal]
final readonly class RegistryLaneSubscribers implements LaneSubscribers
{
    public function __construct(
        private CompiledRegistry $registry,
        private Container $container,
    ) {}

    #[Override]
    public function in(Lane $lane): array
    {
        $bindings = [];

        foreach ($this->registry->subscribers as $entry) {
            if ($entry->lane !== $lane) {
                continue;
            }

            $subscriber = $this->container->make($entry->class);

            $bindings[] = new SubscriberBinding(
                $entry,
                $subscriber instanceof Subscriber ? $subscriber : throw UnusableSubscriber::notASubscriber($entry->class),
            );
        }

        return $bindings;
    }

    #[Override]
    public function named(SubscriptionName $subscription): ?SubscriberEntry
    {
        foreach ($this->registry->subscribers as $entry) {
            if ($entry->name->equals($subscription)) {
                return $entry;
            }
        }

        return null;
    }
}
