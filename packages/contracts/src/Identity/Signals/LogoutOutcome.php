<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;

/**
 * An admitted back-channel logout (PRD 5.16): the sessions it ends. With a sid, the sessions of that
 * IdP session of the connection end, whether or not the token also named the subject; without one,
 * every session of the subject's actor ends. It is not a command: the caller removes the sessions
 * from Valkey, writes the authentication log and answers 200. The next login goes to the identity
 * provider.
 */
#[Experimental]
final readonly class LogoutOutcome
{
    public LogoutScope $scope;

    /**
     * @throws InvalidIdentity when neither a subject nor a session is given
     */
    public function __construct(
        public ConnectionId $connection,
        public Issuer $issuer,
        public SignalId $jti,
        public ?Subject $subject,
        public ?IdpSessionId $session,
    ) {
        if (! $subject instanceof Subject && ! $session instanceof IdpSessionId) {
            throw InvalidIdentity::signalValue('logout outcome', 'for a subject, an IdP session or both');
        }

        $this->scope = $session instanceof IdpSessionId ? LogoutScope::IdpSession : LogoutScope::Subject;
    }

    /**
     * The IdP identity the token named, or null when it named only a session.
     */
    public function identity(): ?IdpIdentity
    {
        return $this->subject instanceof Subject ? new IdpIdentity($this->connection, $this->issuer, $this->subject) : null;
    }
}
