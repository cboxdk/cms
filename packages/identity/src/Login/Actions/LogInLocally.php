<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\SubmittedCredentials;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\SessionToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Identity\LocalAccounts\Domain\LocalConnection;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\Dto\LocalLoginRequest;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginOutcome;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\Dto\ThrottleSecret;
use Cbox\Cms\Identity\Login\Domain\LoginField;
use Cbox\Cms\Identity\Login\Domain\LoginThrottle;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use Cbox\Cms\Identity\LoginPolicy\Actions\CheckLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginAttempt;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginPolicyRefused;
use Cbox\Cms\Identity\Sessions\Actions\EndSessions;
use Cbox\Cms\Identity\Sessions\Actions\IssueSession;

/**
 * A local login with an email address and a password (PRD 5.16), the login path of the panel's
 * login form. In this order:
 *
 * 1. an identifier or a password left empty is refused with validation_required on that field, and
 *    counts as no attempt;
 * 2. an identifier the form could not read as one (TypedLogin::unreadable()), which names no
 *    account, and a request without a client address, which the throttle could not count, are
 *    refused with login_rejected, count as no attempt and check no password;
 * 3. the attempt is counted by the LoginThrottle under the identifier and the IP address, each
 *    hashed with the ThrottleSecret; one above the limit of either is refused with
 *    login_rate_limited, adds 1 to the counter `cms.login.rate_limited` with `cms.limit`, the
 *    ThrottleScope, and checks no password;
 * 4. the local connection starts and completes the login with the identifier and the password in
 *    one go: its flow is Direct, so the pending login never leaves the request, and the form's
 *    protection against forgery is the CSRF token of the request that carries it. The connection
 *    verifies the password exactly once, against the account's hash or a dummy one;
 * 5. the login policy decides on the assertion with the method password (CheckLoginPolicy);
 * 6. a refusal of the connection or the policy is login_rejected, whatever the reason, so an
 *    unknown email, a wrong password and an actor that may not log in look the same;
 * 7. a new session is issued with a new id (IssueSession), and then the account is read again: when
 *    it no longer holds the hash the password was checked against, because a reset or a change set
 *    the password meanwhile, the new session is ended and the login is login_rejected. A reset sets
 *    the hash before it ends the actor's sessions, so a session put before that end is ended by
 *    the reset and one put after it is ended here: no session outlives the old password;
 * 8. a login that got through is taken back from the throttle, and the session the browser still
 *    carried, if any, is ended.
 *
 * No identifier, password or IP address reaches a message, a log entry or a counter.
 */
#[Internal]
final readonly class LogInLocally
{
    public const string RATE_LIMITED = 'cms.login.rate_limited';

    public const string LIMIT = 'cms.limit';

    public function __construct(
        private LocalConnection $connection,
        private CheckLoginPolicy $policy,
        private LoginThrottle $throttle,
        private ThrottleSecret $secret,
        private IssueSession $sessions,
        private EndSessions $ends,
        private Telemetry $telemetry,
    ) {}

    public function login(LocalLoginRequest $request): LoginOutcome
    {
        $password = $request->password;
        $missing = array_values(array_filter([
            $request->login->given ? null : LoginField::Identifier,
            $password instanceof Password ? null : LoginField::Password,
        ]));

        if ($missing !== [] || ! $password instanceof Password) {
            return LoginOutcome::refused(ErrorCode::ValidationRequired, ...$missing);
        }

        $identifier = $request->login->identifier;

        if (! $identifier instanceof LoginIdentifier || ! $request->address instanceof ClientAddress) {
            return LoginOutcome::refused(ErrorCode::LoginRejected);
        }

        $keys = LoginThrottleKeys::of($this->secret, $identifier, $request->address);
        $exceeded = $this->throttle->hit($keys);

        if ($exceeded instanceof ThrottleScope) {
            $this->telemetry->counter(new CounterRecord(new TelemetryName(self::RATE_LIMITED), 1, new Attributes(Attribute::of(self::LIMIT, $exceeded->value))));

            return LoginOutcome::refused(ErrorCode::LoginRateLimited);
        }

        try {
            $pending = $this->connection->start()->pending;
            $verified = $this->connection->completeLocally($pending, new SubmittedCredentials($pending->state->value, $identifier->value, $password->reveal()));
            $decision = $this->policy->check(new LoginAttempt(ActorId::fromString($verified->assertion->subject->value), LoginMethod::Password, $verified->assertion));
        } catch (LoginRefused|LoginPolicyRefused|InvalidUuid7) {
            return LoginOutcome::refused(ErrorCode::LoginRejected);
        }

        $session = $this->sessions->issue($decision);

        if (! $this->connection->stillCurrent($verified)) {
            $this->ends->logout($session->token);

            return LoginOutcome::refused(ErrorCode::LoginRejected);
        }

        $this->throttle->succeeded($keys);
        $this->endPrevious($request->previous);

        return LoginOutcome::loggedIn($session);
    }

    private function endPrevious(?TransportCredential $previous): void
    {
        if (! $previous instanceof TransportCredential) {
            return;
        }

        try {
            $this->ends->logout(SessionToken::parse($previous));
        } catch (CredentialRejected) {
            // A value that is no session id names no session, so there is nothing to end.
        }
    }
}
