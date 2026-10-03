<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\PasswordReset\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Egress\EgressFailed;
use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Identity\Login\Domain\ClientAddress;
use Cbox\Cms\Identity\Login\Domain\Dto\LoginThrottleKeys;
use Cbox\Cms\Identity\Login\Domain\LoginThrottle;
use Cbox\Cms\Identity\Login\Domain\ThrottleScope;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\IssuedResetLink;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetRequest;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetRequestOutcome;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetSettings;
use Cbox\Cms\Identity\PasswordReset\Domain\ResetMail;

/**
 * A request for a password reset link from the panel's page (PRD 5.16). Its answer is the same for
 * every email that is not empty, so it never tells whether an account exists:
 *
 * 1. an email left empty is refused with validation_required, and counts as no request;
 * 2. a request without a client address, which the throttle could not count, sends nothing, and
 *    neither does an email the form could not read as a login identifier (TypedLogin::unreadable()),
 *    which names no account and is not counted;
 * 3. the request is counted by the reset throttle, a LoginThrottle of its own under the identifier
 *    and the IP address with `cbox-cms.identity.password_reset.throttle`; one above a limit sends
 *    nothing, so the page cannot be used to flood a mailbox;
 * 4. a known local account whose actor is active gets a link (IssueResetLink), mailed through the
 *    mail gateway to its login, the account's email; any other email gets nothing;
 * 5. a mail the transport did not take is not the person's to know: the token stays until it
 *    expires, and the gateway counts the failure.
 *
 * Every request that was not empty adds 1 to the counter `cms.password_reset.requests` with
 * `cms.outcome`: `mailed`, `no_account`, `rate_limited`, `mail_failed` or `no_address`. No email, IP address,
 * token or link reaches a message, a log entry or a counter. The time of the answer is not made
 * equal: a mailed link takes the transport's time (docs/security/local-accounts.md).
 */
#[Internal]
final readonly class RequestPasswordReset
{
    public const string REQUESTS = 'cms.password_reset.requests';

    public const string OUTCOME = 'cms.outcome';

    public const string MAILED = 'mailed';

    public const string NO_ACCOUNT = 'no_account';

    public const string RATE_LIMITED = 'rate_limited';

    public const string MAIL_FAILED = 'mail_failed';

    public const string NO_ADDRESS = 'no_address';

    public function __construct(
        private IssueResetLink $links,
        private LoginThrottle $throttle,
        private MailGateway $mail,
        private ResetSettings $settings,
        private Telemetry $telemetry,
    ) {}

    public function request(ResetRequest $request): ResetRequestOutcome
    {
        if (! $request->login->given) {
            return ResetRequestOutcome::emailMissing();
        }

        $this->count($this->answer($request->login->identifier, $request->address));

        return ResetRequestOutcome::taken();
    }

    private function answer(?LoginIdentifier $login, ?ClientAddress $address): string
    {
        if (! $address instanceof ClientAddress) {
            return self::NO_ADDRESS;
        }

        if (! $login instanceof LoginIdentifier) {
            return self::NO_ACCOUNT;
        }

        return $this->throttle->hit(LoginThrottleKeys::of($login, $address)) instanceof ThrottleScope ? self::RATE_LIMITED : $this->send($login);
    }

    private function send(LoginIdentifier $login): string
    {
        $link = $this->links->issue($login)->link;

        if (! $link instanceof IssuedResetLink) {
            return self::NO_ACCOUNT;
        }

        try {
            $this->mail->send(ResetMail::to(new EmailAddress($link->login->value), $link->link(), $this->settings->tokenMinutes));
        } catch (EgressFailed|InvalidIdentity) {
            return self::MAIL_FAILED;
        }

        return self::MAILED;
    }

    private function count(string $outcome): void
    {
        $this->telemetry->counter(new CounterRecord(new TelemetryName(self::REQUESTS), 1, new Attributes(Attribute::of(self::OUTCOME, $outcome))));
    }
}
