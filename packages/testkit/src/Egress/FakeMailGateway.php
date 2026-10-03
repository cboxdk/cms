<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Egress\EgressFailed;
use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Contracts\Egress\EgressOutcome;
use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Contracts\Egress\OutboundMail;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Override;

/**
 * The fake of the mail gateway, for tests of code that sends mail: bind it in place of the default
 * and read sent(). It passes the shared suite MailGatewayContract, as the core's LaravelMailGateway
 * does. It sends nothing: it keeps every mail it took, in order, until goDown(), after which it
 * takes none and fails as a gateway fails when the transport refuses a mail. It counts as a
 * gateway counts.
 */
#[Experimental]
final class FakeMailGateway implements MailGateway
{
    /** @var list<OutboundMail> */
    private array $sent = [];

    private bool $down = false;

    public function __construct(private readonly Telemetry $telemetry = new FakeTelemetry) {}

    public function goDown(): self
    {
        $this->down = true;

        return $this;
    }

    /**
     * Every mail the fake took, in order.
     *
     * @return list<OutboundMail>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    #[Override]
    public function send(OutboundMail $mail): void
    {
        if ($this->down) {
            $this->count($mail, EgressOutcome::Unavailable);

            throw EgressFailed::mailFailed($mail->hostClass);
        }

        $this->sent[] = $mail;
        $this->count($mail, EgressOutcome::Ok);
    }

    private function count(OutboundMail $mail, EgressOutcome $outcome): void
    {
        $attributes = new Attributes(
            Attribute::of(EgressGateway::HOST_CLASS, $mail->hostClass->value),
            Attribute::of(EgressGateway::OUTCOME, $outcome->value),
        );

        $this->telemetry->counter(new CounterRecord(new TelemetryName(MailGateway::MAILS), 1, $attributes));

        if ($outcome->failed()) {
            $this->telemetry->counter(new CounterRecord(new TelemetryName(EgressGateway::FAILURES), 1, $attributes));
        }
    }
}
