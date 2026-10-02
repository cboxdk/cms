<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A local account as a LocalCredentialStore holds it (PRD 5.16, "Lokale konti"): the actor it is
 * bound to, its login identifier, the hash of its password, its version, from 1 and one higher
 * with each change of the hash, when its password was last set and when it was made, both in UTC.
 * A rehash changes the hash and the version, never when the password was set.
 */
#[Experimental]
final readonly class LocalAccount
{
    public const int FIRST_VERSION = 1;

    public DateTimeImmutable $passwordChangedAt;

    public DateTimeImmutable $createdAt;

    /**
     * @throws InvalidIdentity when the version is below 1 or the password was set before the account was made
     */
    public function __construct(
        public ActorId $actor,
        public LoginIdentifier $login,
        public PasswordHash $hash,
        public int $version,
        DateTimeImmutable $passwordChangedAt,
        DateTimeImmutable $createdAt,
    ) {
        if ($version < self::FIRST_VERSION) {
            throw InvalidIdentity::version($version);
        }

        $this->passwordChangedAt = $passwordChangedAt->setTimezone(new DateTimeZone('UTC'));
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));

        if ($this->passwordChangedAt < $this->createdAt) {
            throw InvalidIdentity::localAccountTimes();
        }
    }
}
