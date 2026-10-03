<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The one way out of the process for mail (GUARDRAILS 3), the counterpart of EgressGateway for
 * SMTP and the other mail transports. Every mail of the kernel, its modules and its addons goes
 * through it, and the Arch suite fails on any other use of a mailer. It is a contract (GUARDRAILS
 * 2.3): cbox-cms.contracts binds it to the core's LaravelMailGateway unless an application names
 * another class, the testkit's FakeMailGateway stands in for it in tests, and every implementation
 * runs the shared suite MailGatewayContract.
 *
 * send() hands the mail, as plain text, to the transport the operator configured; the default hands
 * it to Laravel's default mailer, mail.default, from mail.from. The SSRF guard does not apply: the
 * transport's host is the operator's configuration, never input, and the mail's content and its
 * one recipient never choose where the process connects (docs/security/egress.md).
 *
 * Every mail adds 1 to the counter cms.egress.mails, and every one that was not handed over also to
 * cms.egress.failures, each under the host class and the outcome, never the recipient.
 */
#[Experimental]
interface MailGateway
{
    /** The counter of every mail; a mail that was not handed over also counts under EgressGateway::FAILURES. */
    public const string MAILS = 'cms.egress.mails';

    /**
     * @throws EgressFailed with CODE_MAIL_FAILED when the transport refused the mail or did not answer, or the mail has no sender
     */
    public function send(OutboundMail $mail): void;
}
