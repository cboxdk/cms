<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Identity\LoginPolicy\Domain\InvalidLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\LocalFactors;

/**
 * The login policy of this environment (PRD 5.16, "Loginpolitik"), from cbox-cms.identity.policy:
 * a ClassPolicy for staff and one for end users, and the connections marked authoritative, whose
 * identity provider owns the access of the actors linked to them (invariant 38). A service actor
 * never logs in, so it has no policy.
 *
 * The local connection, LOCAL_CONNECTION, is the local accounts of the identity module; every
 * other connection is federated.
 *
 * PRD 5.16 requires a passkey or two factors for a local staff login. Only the development
 * environments, DEVELOPMENT_ENVIRONMENTS, may lower it to a password (assertAllowedIn()), so a
 * production installation cannot be configured into a staff login with a password alone.
 */
#[Internal]
final readonly class LoginPolicy
{
    public const string LOCAL_CONNECTION = 'local';

    /**
     * The environments where a local staff login may need only a password.
     *
     * @var list<string>
     */
    public const array DEVELOPMENT_ENVIRONMENTS = ['local', 'testing'];

    /**
     * @param  list<ConnectionId>  $authoritative
     *
     * @throws InvalidLoginPolicy when the local connection is marked authoritative or a connection is named twice
     */
    public function __construct(
        public ClassPolicy $staff,
        public ClassPolicy $endUser,
        public array $authoritative,
    ) {
        $names = array_map(static fn (ConnectionId $connection): string => $connection->value, $authoritative);

        if (count(array_unique($names)) !== count($names) || in_array(self::LOCAL_CONNECTION, $names, true)) {
            throw InvalidLoginPolicy::key('authoritative_connections', 'a list of federated connections, each once');
        }
    }

    /**
     * Refuses the policy in an environment where it may not hold: outside local and testing, a
     * local staff login needs a passkey or two factors (PRD 5.16).
     *
     * @throws InvalidLoginPolicy when staff may log in locally with a password alone outside local and testing
     */
    public function assertAllowedIn(string $environment): void
    {
        if ($this->staff->localFactors === LocalFactors::Password && ! in_array($environment, self::DEVELOPMENT_ENVIRONMENTS, true)) {
            throw InvalidLoginPolicy::key(
                'cbox-cms.identity.policy.staff.local_factors',
                sprintf('passkey_or_two_factors in the environment %s, because PRD 5.16 requires a passkey or two factors for a local staff login; password is allowed only in %s', $environment, implode(' and ', self::DEVELOPMENT_ENVIRONMENTS)),
            );
        }
    }

    /**
     * The policy of an actor class, or null for a service actor, which never logs in.
     */
    public function of(ActorClass $class): ?ClassPolicy
    {
        return match ($class) {
            ActorClass::Staff => $this->staff,
            ActorClass::EndUser => $this->endUser,
            ActorClass::Service => null,
        };
    }

    public static function isLocal(ConnectionId $connection): bool
    {
        return $connection->value === self::LOCAL_CONNECTION;
    }

    public function isAuthoritative(ConnectionId $connection): bool
    {
        return array_any($this->authoritative, static fn (ConnectionId $listed): bool => $listed->equals($connection));
    }
}
