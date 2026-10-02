<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What a login connection verified about a login (PRD 5.16, "Identitetskontrakten"): who logged
 * in, when and with which factors.
 *
 * - $connection, $issuer and $subject are the IdP identity, identity().
 * - $authTime is when the person authenticated at the identity provider, the auth_time claim, in
 *   UTC; for a direct credential check it is the time of the check. The login policy judges the
 *   age of a login and re-authentication on it.
 * - $amr lists the methods the person authenticated with, in the order the provider gives, each
 *   once. It is empty when the provider sends none, as Google does.
 * - $acr is the authentication context class, or null when the provider sends none.
 * - $groups lists the groups the provider says the person is in, each once, or is null when the
 *   connection sends no groups. An empty list says the connection sends groups and the person is in
 *   none, which is not the same.
 *
 * A method or a group given twice contradicts itself and is refused with InvalidIdentity.
 */
#[Experimental]
final readonly class VerifiedAssertion
{
    public DateTimeImmutable $authTime;

    /**
     * @param  list<AuthenticationMethod>  $amr
     * @param  list<IdpGroup>|null  $groups
     *
     * @throws InvalidIdentity when a method or a group is given twice
     */
    public function __construct(
        public ConnectionId $connection,
        public Issuer $issuer,
        public Subject $subject,
        DateTimeImmutable $authTime,
        public array $amr = [],
        public ?AuthenticationContext $acr = null,
        public ?array $groups = null,
    ) {
        $this->authTime = $authTime->setTimezone(new DateTimeZone('UTC'));

        $this->once('authentication method', array_map(static fn (AuthenticationMethod $method): string => $method->value, $amr));

        if ($groups !== null) {
            $this->once('group', array_map(static fn (IdpGroup $group): string => $group->value, $groups));
        }
    }

    /**
     * The assertion of an IdP identity that an IssuerResolver admitted.
     *
     * @param  list<AuthenticationMethod>  $amr
     * @param  list<IdpGroup>|null  $groups
     *
     * @throws InvalidIdentity when a method or a group is given twice
     */
    public static function of(
        IdpIdentity $identity,
        DateTimeImmutable $authTime,
        array $amr = [],
        ?AuthenticationContext $acr = null,
        ?array $groups = null,
    ): self {
        return new self($identity->connection, $identity->issuer, $identity->subject, $authTime, $amr, $acr, $groups);
    }

    public function identity(): IdpIdentity
    {
        return new IdpIdentity($this->connection, $this->issuer, $this->subject);
    }

    /**
     * Whether the person authenticated with the method, by the amr claim.
     */
    public function authenticatedWith(AuthenticationMethod $method): bool
    {
        return array_any($this->amr, fn (AuthenticationMethod $given): bool => $given->equals($method));
    }

    /**
     * @param  list<string>  $values
     *
     * @throws InvalidIdentity
     */
    private function once(string $what, array $values): void
    {
        if (count(array_unique($values)) !== count($values)) {
            throw InvalidIdentity::duplicate($what);
        }
    }
}
