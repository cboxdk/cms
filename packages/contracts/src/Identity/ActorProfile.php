<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * An actor's profile (PRD 5.16): the name it is shown by and the address to write to. Both are
 * personal data, classified personal (PRD 12.2), and kept apart from the actor's state, so an
 * event, the audit and the actor directory never carry them.
 */
#[Experimental]
final readonly class ActorProfile
{
    /** The classification of every value of a profile. */
    public const ClassificationAccess CLASSIFICATION = ClassificationAccess::Personal;

    public function __construct(
        public DisplayName $displayName,
        public EmailAddress $email,
    ) {}
}
