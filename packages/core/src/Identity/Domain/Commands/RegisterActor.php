<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * Registers an actor (PRD 5.16, 6.4), version 1 of actor.register: the first step of the fixed
 * order of a registration, pending, credential, active. The caller makes the actor's id, so a
 * repeat of the call with the same idempotency key is the same content, and the command expects
 * the actor not to exist: a register of an id that exists is version_conflict (invariant 11).
 *
 * It takes the actor's class, staff or service (an end user is refused until the end-user block,
 * B13), the profile's display name and contact email, which are personal data (ActorProfile), and,
 * for a service actor, the person responsible for it, which must be an active staff actor (PRD
 * 5.16: every service actor has a named responsible person); a staff actor has none. In one
 * changeset the actor is created pending at version 1 and credential generation 1 with its
 * profile, and actor.registered tells about it. A pending actor runs nothing; actor.activate makes
 * it active once its credential is written.
 */
#[CommandName('actor.register', version: 1)]
#[Experimental]
final readonly class RegisterActor implements ExpectsVersions
{
    public function __construct(
        public ActorId $actor,
        public ActorClass $class,
        public DisplayName $displayName,
        public EmailAddress $email,
        public ?ActorId $responsible = null,
    ) {}

    /**
     * The actor, absent.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::absent($this->actor));
    }
}
