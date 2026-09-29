<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Addons\Actions;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Core\Addons\Domain\Dto\ServiceActors;
use Cbox\Cms\Core\Addons\Domain\SubscriberActorUnavailable;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;

/**
 * The actor a subscriber runs as (PRD 13.1, invariant 21). A subscriber of an addon runs as the
 * addon's own service actor, with its own grants, never as the system: the actor configured for
 * the addon, read through the ActorDirectory, which must be an active service actor. A subscriber
 * of a package without a manifest, the application's or a module's, is not an addon's and has
 * none.
 */
#[Internal]
final readonly class ResolveSubscriberActor
{
    public function __construct(
        private ServiceActors $actors,
        private ActorDirectory $directory,
    ) {}

    /**
     * The addon's service actor, or null for a subscriber of no addon.
     *
     * @throws SubscriberActorUnavailable when the addon has no active service actor
     */
    public function for(SubscriberEntry $subscriber): ?Actor
    {
        if (! $subscriber->addon instanceof AddonNamespace) {
            return null;
        }

        $id = $this->actors->of($subscriber->addon) ?? throw SubscriberActorUnavailable::notConfigured($subscriber->class, $subscriber->addon);
        $actor = $this->directory->find($id) ?? throw SubscriberActorUnavailable::unknown($subscriber->class, $subscriber->addon, $id);

        if ($actor->class !== ActorClass::Service || ! $actor->isActive()) {
            throw SubscriberActorUnavailable::unusable($subscriber->class, $subscriber->addon, $actor);
        }

        return $actor;
    }
}
