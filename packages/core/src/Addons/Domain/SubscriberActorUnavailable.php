<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Addons\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Ids\ActorId;
use RuntimeException;

/**
 * An addon's subscriber cannot run, because the addon has no service actor it may run as
 * (PRD 13.1, invariant 21): none is configured in `cbox-cms.addons.service_actors`, no actor has
 * the configured id, or the actor is not an active service actor. It never runs as the system
 * instead.
 */
#[Internal]
final class SubscriberActorUnavailable extends RuntimeException
{
    public const string CODE = 'addon_service_actor_unavailable';

    public static function notConfigured(string $subscriber, AddonNamespace $addon): self
    {
        return new self(sprintf(
            'The subscriber %s of addon "%s" cannot run: no service actor is configured for the addon. Set cbox-cms.addons.service_actors.%s to the id of the service actor created when its capabilities were approved.',
            $subscriber,
            $addon->value,
            $addon->value,
        ));
    }

    public static function unknown(string $subscriber, AddonNamespace $addon, ActorId $id): self
    {
        return new self(sprintf(
            'The subscriber %s of addon "%s" cannot run: no actor has the configured service actor id %s. Correct cbox-cms.addons.service_actors.%s.',
            $subscriber,
            $addon->value,
            $id->toString(),
            $addon->value,
        ));
    }

    public static function unusable(string $subscriber, AddonNamespace $addon, Actor $actor): self
    {
        return new self(sprintf(
            'The subscriber %s of addon "%s" cannot run: its service actor %s is a %s actor in the state %s, and an addon runs only as an active service actor. Point cbox-cms.addons.service_actors.%s at one, or reactivate the actor.',
            $subscriber,
            $addon->value,
            $actor->id->toString(),
            $actor->class->value,
            $actor->state->value,
            $addon->value,
        ));
    }
}
