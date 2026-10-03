<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Identity\PasswordResetRefused;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Contracts\Identity\SessionToken;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Identity\LocalAccounts\Domain\LocalConnection;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordPolicy;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordRefused;
use Cbox\Cms\Identity\LoginPolicy\Actions\CheckLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginAttempt;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginPolicyRefused;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\PasswordResetOutcome;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\PasswordResetSubmission;
use Cbox\Cms\Identity\Sessions\Actions\EndSessions;
use Cbox\Cms\Identity\Sessions\Actions\IssueSession;
use Cbox\Cms\Identity\Sessions\Domain\Dto\NewSession;

/**
 * Sets a new password with the token of a reset link (PRD 5.16), the login path of the reset page.
 * In this order:
 *
 * 1. a text that is not in the form of a token, or whose checksum does not match, is refused with
 *    password_reset_token_invalid before any lookup, and so is a token the store does not hold
 *    usable, looked up without taking it, so a dead link costs no breach check and no hashing, and
 *    so is a token of an actor the login policy would not let log in by password reset
 *    (CheckLoginPolicy::admitLocal(): not active, password_reset or local login off for its class,
 *    or linked to an authoritative connection, invariant 38), which changes nothing;
 * 2. an empty password is validation_required, and one the password policy refuses is refused with
 *    its code (at least 12 characters, at most 1024 bytes, not breached); when the breach check
 *    cannot be made, breached_passwords_unavailable, and the token stays usable;
 * 3. the policy is asked that again, since the breach check may have taken a while, and then the
 *    store takes the token once, before it expires, and sets the new hash with it, both or
 *    neither; a token that is unknown, used or expired is password_reset_token_invalid, one code
 *    for all three;
 * 4. every session of the actor is ended, through the store's set of the actor's sessions, and the
 *    session the browser still carried too, whoever's it was;
 * 5. the local connection asserts the login, and the login policy decides it with the method
 *    password_reset; a refusal, which after steps 1 and 3 is the factors a reset cannot give (a
 *    class that requires a passkey or two factors) or a change in between, leaves the password set
 *    and logs no one in (changed()), and a login it allows gets a new session with a new id
 *    (IssueSession).
 *
 * Every reset adds 1 to the counter `cms.password_reset.resets` with `cms.outcome` `logged_in`,
 * `changed` or `refused`, and for a refusal `cms.error.code`. No token, password or actor reaches
 * a message, a log entry or a counter.
 */
#[Internal]
final readonly class ResetPassword
{
    public const string RESETS = 'cms.password_reset.resets';

    public const string OUTCOME = 'cms.outcome';

    public const string ERROR_CODE = 'cms.error.code';

    public function __construct(
        private LocalCredentialStore $store,
        private PasswordPolicy $passwords,
        private PasswordHasher $hasher,
        private LocalConnection $connection,
        private CheckLoginPolicy $policy,
        private EndSessions $ends,
        private IssueSession $sessions,
        private Telemetry $telemetry,
    ) {}

    public function reset(PasswordResetSubmission $submission): PasswordResetOutcome
    {
        $outcome = $this->decide($submission);

        $this->telemetry->counter(new CounterRecord(new TelemetryName(self::RESETS), 1, new Attributes(...match (true) {
            $outcome->refusal instanceof ErrorCode => [Attribute::of(self::OUTCOME, 'refused'), Attribute::of(self::ERROR_CODE, $outcome->refusal->value)],
            ! $outcome->session instanceof NewSession => [Attribute::of(self::OUTCOME, 'changed')],
            default => [Attribute::of(self::OUTCOME, 'logged_in')],
        })));

        return $outcome;
    }

    private function decide(PasswordResetSubmission $submission): PasswordResetOutcome
    {
        $token = PasswordResetToken::parse($submission->token());

        $actor = $token instanceof PasswordResetToken ? $this->store->resetTokenActor($token) : null;

        if (! $token instanceof PasswordResetToken || ! $actor instanceof ActorId || ! $this->admitted($actor)) {
            return PasswordResetOutcome::refused(ErrorCode::PasswordResetTokenInvalid);
        }

        if ($submission->password() === '') {
            return PasswordResetOutcome::refused(ErrorCode::ValidationRequired);
        }

        $password = new Password($submission->password());

        try {
            $this->passwords->check($password);
        } catch (PasswordRefused $refused) {
            return PasswordResetOutcome::refused($refused->code());
        } catch (BreachedPasswordsUnavailable) {
            return PasswordResetOutcome::refused(ErrorCode::BreachedPasswordsUnavailable);
        }

        if (! $this->admitted($actor)) {
            return PasswordResetOutcome::refused(ErrorCode::PasswordResetTokenInvalid);
        }

        try {
            $account = $this->store->resetPassword($token, $this->hasher->hash($password));
        } catch (PasswordResetRefused) {
            return PasswordResetOutcome::refused(ErrorCode::PasswordResetTokenInvalid);
        }

        $this->ends->ofActor($account->actor);
        $this->endPrevious($submission->previous);

        try {
            $decision = $this->policy->check(new LoginAttempt($account->actor, LoginMethod::PasswordReset, $this->connection->resetAssertion($account)));
        } catch (LoginPolicyRefused) {
            return PasswordResetOutcome::changed();
        }

        return PasswordResetOutcome::loggedIn($this->sessions->issue($decision));
    }

    /**
     * Whether the login policy would let the actor log in by password reset, so the reset may set
     * a local password at all (PRD 5.16, invariant 38). It reads the actor and its links each
     * time, so a second call can answer otherwise.
     *
     * @phpstan-impure
     */
    private function admitted(ActorId $actor): bool
    {
        try {
            $this->policy->admitLocal($actor, LoginMethod::PasswordReset);
        } catch (LoginPolicyRefused) {
            return false;
        }

        return true;
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
