<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Egress\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * The gateway sent no request, or got no answer it hands on. The message names the host class and
 * why, never the URL, its path or its query (GUARDRAILS 6), and no exception that could hold them is
 * chained.
 */
#[Experimental]
final class EgressFailed extends RuntimeException
{
    /** The SSRF guard refused the destination: a private, reserved or metadata address, a blocked host, a scheme other than https or credentials in the URL. */
    public const string CODE_BLOCKED = 'egress_blocked';

    /** The destination answered with a redirect, which the gateway never follows. */
    public const string CODE_REDIRECT_REFUSED = 'egress_redirect_refused';

    /** The destination did not answer within the timeouts, or the connection failed. */
    public const string CODE_UNAVAILABLE = 'egress_unavailable';

    /** The mail transport refused a mail or did not answer, or the mail has no sender (MailGateway). */
    public const string CODE_MAIL_FAILED = 'egress_mail_failed';

    /** The SSRF guard's policy does not enforce its checks or does not pin DNS. */
    public const string CODE_GUARD_DISABLED = 'egress_guard_disabled';

    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly EgressOutcome $outcome,
        public readonly HostClass $hostClass,
    ) {
        parent::__construct($message);
    }

    public static function blocked(HostClass $hostClass): self
    {
        return new self(
            sprintf('The outbound request for %s was refused by the SSRF guard: its destination is a private, reserved or metadata address or a blocked host, or its URL has another scheme than https or carries credentials.', $hostClass->value),
            self::CODE_BLOCKED,
            EgressOutcome::Blocked,
            $hostClass,
        );
    }

    public static function redirect(HostClass $hostClass, int $status): self
    {
        return new self(
            sprintf('The destination of the outbound request for %s answered with the redirect %d, which the gateway does not follow.', $hostClass->value, $status),
            self::CODE_REDIRECT_REFUSED,
            EgressOutcome::Redirect,
            $hostClass,
        );
    }

    public static function unavailable(HostClass $hostClass): self
    {
        return new self(
            sprintf('The destination of the outbound request for %s did not answer within the timeouts of cbox-cms.egress, or the connection failed.', $hostClass->value),
            self::CODE_UNAVAILABLE,
            EgressOutcome::Unavailable,
            $hostClass,
        );
    }

    public static function mailFailed(HostClass $hostClass): self
    {
        return new self(
            sprintf('The mail for %s was not handed to the mail transport: the transport refused it or did not answer, or the mail has no sender. Check mail.default, its mailer in mail.mailers and mail.from.', $hostClass->value),
            self::CODE_MAIL_FAILED,
            EgressOutcome::Unavailable,
            $hostClass,
        );
    }

    public static function guardDisabled(HostClass $hostClass): self
    {
        return new self(
            sprintf('The outbound request for %s was not sent: the SSRF guard\'s policy, ssrf.enforce and ssrf.pin_dns, must both be on.', $hostClass->value),
            self::CODE_GUARD_DISABLED,
            EgressOutcome::GuardDisabled,
            $hostClass,
        );
    }
}
