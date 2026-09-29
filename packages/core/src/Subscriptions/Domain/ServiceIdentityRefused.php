<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Ids\ActorId;
use RuntimeException;

/**
 * The event runner runs its subscribers as a service identity, never as the system (PRD 6.5
 * invariant 21, 13.1), and refuses to run without one it can use: cbox-cms.events.runner.service_actor
 * names no actor, an actor the ActorDirectory does not know or one that is not a service actor
 * (CODE), or one that is not active (CODE_NOT_ACTIVE, PRD 5.16).
 */
#[Experimental]
final class ServiceIdentityRefused extends RuntimeException
{
    public const string CODE = 'subscription_identity_invalid';

    public const string CODE_NOT_ACTIVE = 'actor_not_active';

    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('The event runner runs its subscribers as a service actor, and cbox-cms.events.runner.service_actor names none. Create a service actor and name its id there.', self::CODE);
    }

    public static function unknown(ActorId $actor): self
    {
        return new self(sprintf('The service actor %s that cbox-cms.events.runner.service_actor names does not exist.', $actor->toString()), self::CODE);
    }

    public static function notAService(ActorId $actor, ActorClass $class): self
    {
        return new self(sprintf('The actor %s that cbox-cms.events.runner.service_actor names is a %s actor; the runner runs only as a service actor.', $actor->toString(), $class->value), self::CODE);
    }

    public static function notActive(ActorId $actor, ActorState $state): self
    {
        return new self(sprintf('The service actor %s is %s, not active, so its subscribers do not run (PRD 5.16).', $actor->toString(), $state->value), self::CODE_NOT_ACTIVE);
    }
}
