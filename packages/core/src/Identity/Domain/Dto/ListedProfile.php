<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;

/**
 * An actor's profile as a listing gives it (PRD 5.16, 12.2): its display name and email, both
 * personal data. A reader whose classification access does not allow personal gets each as
 * Omitted, through visibleTo() and the result's codec at the context's access, so the email of
 * another actor never reaches it.
 */
#[Experimental]
final readonly class ListedProfile
{
    public function __construct(
        public DisplayName|Omitted $displayName,
        public EmailAddress|Omitted $email,
    ) {}

    public static function of(ActorProfile $profile): self
    {
        return new self($profile->displayName, $profile->email);
    }

    /**
     * This profile as a reader with $access may see it.
     */
    public function visibleTo(ClassificationAccess $access): self
    {
        return $access->allows(ActorProfile::CLASSIFICATION) ? $this : new self(Omitted::Field, Omitted::Field);
    }
}
