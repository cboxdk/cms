<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Egress\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Egress\EgressFailed;
use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Contracts\Egress\EgressOutcome;
use Cbox\Cms\Contracts\Egress\HostClass;
use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Contracts\Egress\OutboundMail;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Override;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\ExceptionInterface;

/**
 * The mail gateway on Laravel's default mailer (GUARDRAILS 3): the transport of mail.default, with
 * the sender of mail.from, both the operator's configuration. It sends the mail's text as plain
 * text to its one recipient with its subject.
 *
 * A transport that refuses the mail or does not answer, and a mail without a sender, fail with
 * EgressFailed::mailFailed(); the exception of the transport is not chained, because its message
 * can name the recipient or the server's answer about it.
 */
#[Experimental]
final readonly class LaravelMailGateway implements MailGateway
{
    public function __construct(
        private Mailer $mailer,
        private Telemetry $telemetry,
    ) {}

    #[Override]
    public function send(OutboundMail $mail): void
    {
        try {
            $this->mailer->raw($mail->text, static function (Message $message) use ($mail): void {
                $message->to($mail->to->value)->subject($mail->subject);
            });
        } catch (TransportExceptionInterface|ExceptionInterface) {
            $this->count($mail->hostClass, EgressOutcome::Unavailable);

            throw EgressFailed::mailFailed($mail->hostClass);
        }

        $this->count($mail->hostClass, EgressOutcome::Ok);
    }

    private function count(HostClass $hostClass, EgressOutcome $outcome): void
    {
        $attributes = new Attributes(
            Attribute::of(EgressGateway::HOST_CLASS, $hostClass->value),
            Attribute::of(EgressGateway::OUTCOME, $outcome->value),
        );

        $this->telemetry->counter(new CounterRecord(new TelemetryName(MailGateway::MAILS), 1, $attributes));

        if ($outcome->failed()) {
            $this->telemetry->counter(new CounterRecord(new TelemetryName(EgressGateway::FAILURES), 1, $attributes));
        }
    }
}
