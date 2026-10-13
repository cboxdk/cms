<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * A service actor to register (PRD 5.16): its display name, its contact email and the active staff
 * actor responsible for it, which every service actor has.
 */
#[Internal]
final readonly class ServiceRegistration
{
    public function __construct(
        public DisplayName $displayName,
        public EmailAddress $email,
        public ActorId $responsible,
    ) {}
}
