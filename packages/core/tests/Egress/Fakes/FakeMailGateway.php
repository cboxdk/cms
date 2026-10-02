<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress\Fakes;

use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Core\Egress\Adapter\LaravelMailGateway;
use Cbox\Cms\Core\Egress\Adapter\SsrfEgressGateway;
use Cbox\Cms\Core\Egress\Domain\Dto\OutboundMail;
use Cbox\Cms\Core\Egress\Domain\EgressFailed;
use Cbox\Cms\Core\Egress\Domain\EgressOutcome;
use Cbox\Cms\Core\Egress\Domain\MailGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Override;

/**
 * The fake of the mail gateway, held to LaravelMailGateway by MailGatewayBehaviour. It sends
 * nothing: it keeps every mail it took, in order, until goDown(), after which it takes none and
 * fails as the gateway fails when the transport refuses a mail. It counts as the gateway counts.
 */
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
            Attribute::of(SsrfEgressGateway::HOST_CLASS, $mail->hostClass->value),
            Attribute::of(SsrfEgressGateway::OUTCOME, $outcome->value),
        );

        $this->telemetry->counter(new CounterRecord(new TelemetryName(LaravelMailGateway::MAILS), 1, $attributes));

        if ($outcome->failed()) {
            $this->telemetry->counter(new CounterRecord(new TelemetryName(SsrfEgressGateway::FAILURES), 1, $attributes));
        }
    }
}
