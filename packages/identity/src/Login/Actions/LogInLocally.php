<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Login\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\Login\LoginRefused;
use Cbox\Cms\Contracts\Identity\Login\SubmittedCredentials;
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
use Cbox\Cms\Identity\Login\Domain\Dto\LocalLoginRequest;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginOutcome;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
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
 * 2. the attempt is counted by the LoginThrottle under the identifier and the IP address; one
 *    above the limit of either is refused with login_rate_limited, adds 1 to the counter
 *    `cms.login.rate_limited` with `cms.limit`, the ThrottleScope, and checks no password;
 * 3. the local connection starts and completes the login with the identifier and the password in
 *    one go: its flow is Direct, so the pending login never leaves the request, and the form's
 *    protection against forgery is the CSRF token of the request that carries it. The connection
 *    verifies the password exactly once, against the account's hash or a dummy one;
 * 4. the login policy decides on the assertion with the method password (CheckLoginPolicy);
 * 5. a refusal of the connection or the policy is login_rejected, whatever the reason, so an
 *    unknown email, a wrong password and an actor that may not log in look the same;
 * 6. a login that got through is taken back from the throttle, the session the browser still
 *    carried, if any, is ended, and a new session is issued with a new id (IssueSession).
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
        private IssueSession $sessions,
        private EndSessions $ends,
        private Telemetry $telemetry,
    ) {}

    public function login(LocalLoginRequest $request): LoginOutcome
    {
        $missing = array_values(array_filter([
            trim($request->identifier) === '' ? LoginField::Identifier : null,
            $request->password() === '' ? LoginField::Password : null,
        ]));

        if ($missing !== []) {
            return LoginOutcome::refused(ErrorCode::ValidationRequired, ...$missing);
        }

        $keys = LoginThrottleKeys::of($request->identifier, $request->ip);
        $exceeded = $this->throttle->hit($keys);

        if ($exceeded instanceof ThrottleScope) {
            $this->telemetry->counter(new CounterRecord(new TelemetryName(self::RATE_LIMITED), 1, new Attributes(Attribute::of(self::LIMIT, $exceeded->value))));

            return LoginOutcome::refused(ErrorCode::LoginRateLimited);
        }

        try {
            $pending = $this->connection->start()->pending;
            $assertion = $this->connection->complete($pending, new SubmittedCredentials($pending->state->value, $request->identifier, $request->password()));
            $decision = $this->policy->check(new LoginAttempt(ActorId::fromString($assertion->subject->value), LoginMethod::Password, $assertion));
        } catch (LoginRefused|LoginPolicyRefused|InvalidUuid7) {
            return LoginOutcome::refused(ErrorCode::LoginRejected);
        }

        $this->throttle->succeeded($keys);
        $this->endPrevious($request->previous);

        return LoginOutcome::loggedIn($this->sessions->issue($decision));
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
