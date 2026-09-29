<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Subscribers\Lane;

/**
 * An event class an addon's subscribers may receive, and the lane they receive it in (PRD 13.1,
 * 7.6). cms:build refuses a #[Subscription] of the addon's package that lists an event class on a
 * lane no AllowedSubscription of its manifest names, as registry_undeclared_subscriber.
 */
#[Experimental]
final readonly class AllowedSubscription
{
    public string $event;

    /**
     * @param  string  $event  the event class, such as PagePublished::class
     *
     * @throws InvalidAddonManifest when the event is not a class name
     */
    public function __construct(
        string $event,
        public Lane $lane,
    ) {
        $this->event = ClassNames::check('an allowed subscription\'s event', $event);
    }

    /**
     * Whether it allows a subscriber to receive the event class on the lane. PHP class names are
     * compared without case, as PHP compares them.
     */
    public function allows(string $event, Lane $lane): bool
    {
        return $lane === $this->lane && strcasecmp(ltrim($event, '\\'), $this->event) === 0;
    }
}
