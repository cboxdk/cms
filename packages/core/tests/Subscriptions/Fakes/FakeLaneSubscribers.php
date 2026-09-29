<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions\Fakes;

use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\SubscriberBinding;
use Cbox\Cms\Core\Subscriptions\Domain\LaneSubscribers;
use Override;

/**
 * The subscribers the runner's tests register, given as bindings.
 */
final readonly class FakeLaneSubscribers implements LaneSubscribers
{
    /**
     * @param  list<SubscriberBinding>  $bindings  in registry order
     */
    public function __construct(private array $bindings) {}

    #[Override]
    public function in(Lane $lane): array
    {
        return array_values(array_filter($this->bindings, static fn (SubscriberBinding $binding): bool => $binding->entry->lane === $lane));
    }

    #[Override]
    public function named(SubscriptionName $subscription): ?SubscriberEntry
    {
        foreach ($this->bindings as $binding) {
            if ($binding->entry->name->equals($subscription)) {
                return $binding->entry;
            }
        }

        return null;
    }
}
