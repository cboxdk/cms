<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Egress\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Egress\Domain\Dto\OutboundMail;

/**
 * The one way out of the process for mail (GUARDRAILS 3), the counterpart of EgressGateway for
 * SMTP and the other mail transports. Every mail of the kernel, its modules and its addons goes
 * through it, and the Arch suite fails on any other use of a mailer.
 *
 * send() hands the mail to the transport of the installation's default mailer, mail.default in
 * Laravel's configuration, as plain text from mail.from. The SSRF guard does not apply: the
 * transport's host is the operator's configuration, never input, and the mail's content and its
 * one recipient never choose where the process connects (docs/security/egress.md).
 *
 * Every mail adds 1 to the counter cms.egress.mails, and every one that was not handed over also to
 * cms.egress.failures, each under the host class and the outcome, never the recipient.
 */
#[Experimental]
interface MailGateway
{
    /**
     * @throws EgressFailed with CODE_MAIL_FAILED when the transport refused the mail or did not answer, or the mail has no sender
     */
    public function send(OutboundMail $mail): void;
}
