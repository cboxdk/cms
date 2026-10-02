<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Core\Egress\Adapter\LaravelMailGateway;
use Cbox\Cms\Core\Egress\Adapter\SsrfEgressGateway;
use Cbox\Cms\Core\Egress\Domain\Dto\OutboundMail;
use Cbox\Cms\Core\Egress\Domain\EgressFailed;
use Cbox\Cms\Core\Egress\Domain\HostClass;
use Cbox\Cms\Core\Egress\Domain\MailGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every MailGateway does, run against LaravelMailGateway and FakeMailGateway (GUARDRAILS 9):
 * it hands each mail to its one recipient with its subject and text, fails with
 * egress_mail_failed when the transport does not take it, without the recipient in the message,
 * and counts every mail and every failure under the host class and the outcome.
 */
trait MailGatewayBehaviour
{
    /**
     * A gateway that counts on the given telemetry.
     */
    abstract protected function gateway(FakeTelemetry $telemetry): MailGateway;

    /**
     * The transport takes no mail from now on.
     */
    abstract protected function breakTransport(): void;

    /**
     * Each mail the transport took, in order, as its recipient, subject and text.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    abstract protected function delivered(): array;

    #[Test]
    public function it_hands_each_mail_to_its_recipient_with_its_subject_and_text_and_counts_it(): void
    {
        $telemetry = new FakeTelemetry;
        $gateway = $this->gateway($telemetry);

        $gateway->send($this->mail('ada@example.org', "First line\n\nhttps://cms.example.com/reset/1\n"));
        $gateway->send($this->mail('grace@example.org', 'Another text'));

        Assert::assertSame([
            ['ada@example.org', 'A subject', "First line\n\nhttps://cms.example.com/reset/1\n"],
            ['grace@example.org', 'A subject', 'Another text'],
        ], $this->delivered());
        Assert::assertSame([['probe', 'ok'], ['probe', 'ok']], $this->counted($telemetry, LaravelMailGateway::MAILS));
        Assert::assertSame([], $this->counted($telemetry, SsrfEgressGateway::FAILURES));
    }

    #[Test]
    public function it_fails_with_egress_mail_failed_when_the_transport_does_not_take_the_mail(): void
    {
        $telemetry = new FakeTelemetry;
        $gateway = $this->gateway($telemetry);
        $this->breakTransport();

        try {
            $gateway->send($this->mail('ada@example.org', 'The text'));
            Assert::fail('The mail was taken.');
        } catch (EgressFailed $failed) {
            Assert::assertSame(EgressFailed::CODE_MAIL_FAILED, $failed->errorCode);
            Assert::assertStringNotContainsString('ada@example.org', $failed->getMessage());
            Assert::assertNull($failed->getPrevious());
        }

        Assert::assertSame([], $this->delivered());
        Assert::assertSame([['probe', 'unavailable']], $this->counted($telemetry, LaravelMailGateway::MAILS));
        Assert::assertSame([['probe', 'unavailable']], $this->counted($telemetry, SsrfEgressGateway::FAILURES));
    }

    private function mail(string $to, string $text): OutboundMail
    {
        return new OutboundMail(new HostClass('probe'), new EmailAddress($to), 'A subject', $text);
    }

    /**
     * Each counter of the name's host class and outcome, in the order counted.
     *
     * @return list<array{0: string|int|float|bool|null, 1: string|int|float|bool|null}>
     */
    private function counted(FakeTelemetry $telemetry, string $name): array
    {
        $counted = [];

        foreach ($telemetry->counters() as $counter) {
            if ($counter->name->value === $name) {
                $counted[] = [$counter->attributes->get(SsrfEgressGateway::HOST_CLASS), $counter->attributes->get(SsrfEgressGateway::OUTCOME)];
            }
        }

        return $counted;
    }
}
