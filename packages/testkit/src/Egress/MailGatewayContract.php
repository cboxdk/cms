<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Egress\EgressFailed;
use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Contracts\Egress\HostClass;
use Cbox\Cms\Contracts\Egress\MailGateway;
use Cbox\Cms\Contracts\Egress\OutboundMail;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for MailGateway (GUARDRAILS 2.3, 3 and 9). The fake and every real
 * gateway run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * fresh harness for each case:
 *
 *     final class FakeMailGatewayContractTest extends TestCase
 *     {
 *         use MailGatewayContract;
 *
 *         protected function harness(): MailGatewayHarness
 *         {
 *             return new FakeMailGatewayHarness;
 *         }
 *     }
 *
 * The cases: a gateway hands each mail to its one recipient with its subject and text, fails with
 * egress_mail_failed when the transport does not take the mail, without the recipient in the
 * message and without the transport's exception, and counts every mail and every failure under the
 * host class and the outcome.
 */
#[Experimental]
trait MailGatewayContract
{
    /**
     * A fresh harness whose transport has taken no mail and takes every mail.
     */
    abstract protected function harness(): MailGatewayHarness;

    #[Test]
    public function it_hands_each_mail_to_its_recipient_with_its_subject_and_text_and_counts_it(): void
    {
        $harness = $this->harness();
        $telemetry = new FakeTelemetry;
        $gateway = $harness->gateway($telemetry);

        $gateway->send($this->outboundMail('ada@example.org', "First line\n\nhttps://cms.example.com/reset/1\n"));
        $gateway->send($this->outboundMail('grace@example.org', 'Another text'));

        Assert::assertSame([
            ['ada@example.org', 'A subject', "First line\n\nhttps://cms.example.com/reset/1\n"],
            ['grace@example.org', 'A subject', 'Another text'],
        ], $harness->delivered());
        Assert::assertSame([['probe', 'ok'], ['probe', 'ok']], $this->mailCounted($telemetry, MailGateway::MAILS));
        Assert::assertSame([], $this->mailCounted($telemetry, EgressGateway::FAILURES));
    }

    #[Test]
    public function it_fails_with_egress_mail_failed_when_the_transport_does_not_take_the_mail(): void
    {
        $harness = $this->harness();
        $telemetry = new FakeTelemetry;
        $gateway = $harness->gateway($telemetry);
        $harness->breakTransport();

        try {
            $gateway->send($this->outboundMail('ada@example.org', 'The text'));
            Assert::fail('The mail was taken.');
        } catch (EgressFailed $failed) {
            Assert::assertSame(EgressFailed::CODE_MAIL_FAILED, $failed->errorCode);
            Assert::assertStringNotContainsString('ada@example.org', $failed->getMessage());
            Assert::assertNull($failed->getPrevious());
        }

        Assert::assertSame([], $harness->delivered());
        Assert::assertSame([['probe', 'unavailable']], $this->mailCounted($telemetry, MailGateway::MAILS));
        Assert::assertSame([['probe', 'unavailable']], $this->mailCounted($telemetry, EgressGateway::FAILURES));
    }

    private function outboundMail(string $to, string $text): OutboundMail
    {
        return new OutboundMail(new HostClass('probe'), new EmailAddress($to), 'A subject', $text);
    }

    /**
     * Each counter of the name's host class and outcome, in the order counted.
     *
     * @return list<list<string|int|float|bool|null>>
     */
    private function mailCounted(FakeTelemetry $telemetry, string $name): array
    {
        $counted = [];

        foreach ($telemetry->counters() as $counter) {
            if ($counter->name->value === $name) {
                $counted[] = [$counter->attributes->get(EgressGateway::HOST_CLASS), $counter->attributes->get(EgressGateway::OUTCOME)];
            }
        }

        return $counted;
    }
}
