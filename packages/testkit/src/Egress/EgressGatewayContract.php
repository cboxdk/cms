<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Egress;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Egress\EgressFailed;
use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Contracts\Egress\EgressHeader;
use Cbox\Cms\Contracts\Egress\EgressRequest;
use Cbox\Cms\Contracts\Egress\EgressResponse;
use Cbox\Cms\Contracts\Egress\HostClass;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for EgressGateway (GUARDRAILS 2.3, 3 and 9). The fake and every real
 * gateway run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * fresh harness for each case:
 *
 *     final class FakeEgressGatewayContractTest extends TestCase
 *     {
 *         use EgressGatewayContract;
 *
 *         protected function harness(): EgressGatewayHarness
 *         {
 *             return new FakeEgressGatewayHarness;
 *         }
 *     }
 *
 * The cases: a gateway hands on every answer but a redirect, refuses a redirect, a destination
 * that resolves to a private address and a scheme other than https, fails as unavailable when
 * nothing answers, sends the headers it is given, never names the URL in a failure, and counts
 * every request and every failure under the host class and the outcome.
 */
#[Experimental]
trait EgressGatewayContract
{
    /**
     * A fresh harness: no URL answers yet, and only api.example.com resolves, to a public address.
     */
    abstract protected function harness(): EgressGatewayHarness;

    #[Test]
    public function it_hands_on_every_answer_but_a_redirect_and_counts_it(): void
    {
        $harness = $this->harness();
        $telemetry = new FakeTelemetry;
        $gateway = $harness->gateway($telemetry);
        $harness->answer('https://api.example.com/ok', 200, 'fine');
        $harness->answer('https://api.example.com/missing', 404, 'nothing here');

        $ok = $gateway->get($this->egressRequest('https://api.example.com/ok', [new EgressHeader('Add-Padding', 'true')]));
        $missing = $gateway->get($this->egressRequest('https://api.example.com/missing'));

        Assert::assertSame([200, 'fine'], [$ok->status, $ok->body]);
        Assert::assertSame([404, 'nothing here'], [$missing->status, $missing->body]);
        Assert::assertSame(2, $telemetry->counted(EgressGateway::REQUESTS));
        Assert::assertSame(1, $telemetry->counted(EgressGateway::FAILURES));
        Assert::assertSame([['probe', 'ok'], ['probe', 'status'], ['probe', 'status']], $this->egressCounted($telemetry));
    }

    #[Test]
    public function it_sends_the_headers_it_is_given(): void
    {
        $harness = $this->harness();
        $gateway = $harness->gateway(new FakeTelemetry);
        $harness->answer('https://api.example.com/range/ABCDE', 200, 'x');

        $gateway->get($this->egressRequest('https://api.example.com/range/ABCDE', [new EgressHeader('Add-Padding', 'true')]));

        Assert::assertSame('true', $harness->sentHeader('Add-Padding'));
    }

    #[Test]
    public function it_refuses_a_redirect(): void
    {
        $harness = $this->harness();
        $telemetry = new FakeTelemetry;
        $gateway = $harness->gateway($telemetry);
        $harness->answer('https://api.example.com/moved', 302);

        $failed = $this->egressFailure(fn () => $gateway->get($this->egressRequest('https://api.example.com/moved')));

        Assert::assertSame(EgressFailed::CODE_REDIRECT_REFUSED, $failed->errorCode);
        Assert::assertSame([['probe', 'redirect'], ['probe', 'redirect']], $this->egressCounted($telemetry));
    }

    #[Test]
    public function it_refuses_a_destination_the_guard_blocks_and_a_scheme_other_than_https(): void
    {
        $harness = $this->harness();
        $telemetry = new FakeTelemetry;
        $gateway = $harness->gateway($telemetry);
        $harness->pointAtPrivateAddress('intranet.example.com');
        $harness->answer('https://api.example.com/plain', 200, 'fine');

        $private = $this->egressFailure(fn () => $gateway->get($this->egressRequest('https://intranet.example.com/admin')));
        $plain = $this->egressFailure(fn () => $gateway->get($this->egressRequest('http://api.example.com/plain')));

        Assert::assertSame(EgressFailed::CODE_BLOCKED, $private->errorCode);
        Assert::assertSame(EgressFailed::CODE_BLOCKED, $plain->errorCode);
        Assert::assertSame(2, $telemetry->counted(EgressGateway::FAILURES));
        Assert::assertSame([['probe', 'blocked'], ['probe', 'blocked'], ['probe', 'blocked'], ['probe', 'blocked']], $this->egressCounted($telemetry));
    }

    #[Test]
    public function it_fails_as_unavailable_when_nothing_answers(): void
    {
        $harness = $this->harness();
        $telemetry = new FakeTelemetry;
        $gateway = $harness->gateway($telemetry);
        $harness->goDown('https://api.example.com/down');

        $failed = $this->egressFailure(fn () => $gateway->get($this->egressRequest('https://api.example.com/down')));

        Assert::assertSame(EgressFailed::CODE_UNAVAILABLE, $failed->errorCode);
        Assert::assertSame([['probe', 'unavailable'], ['probe', 'unavailable']], $this->egressCounted($telemetry));
    }

    /**
     * @param  list<EgressHeader>  $headers
     */
    private function egressRequest(string $url, array $headers = []): EgressRequest
    {
        return new EgressRequest(new HostClass('probe'), $url, $headers);
    }

    /**
     * The failure the send ends in, whose message never names the URL.
     *
     * @param  callable(): EgressResponse  $send
     */
    private function egressFailure(callable $send): EgressFailed
    {
        try {
            $send();
        } catch (EgressFailed $failed) {
            Assert::assertStringNotContainsString('example.com', $failed->getMessage());
            Assert::assertNull($failed->getPrevious());

            return $failed;
        }

        Assert::fail('The request did not fail.');
    }

    /**
     * Each counter's host class and outcome, in the order counted.
     *
     * @return list<list<string|int|float|bool|null>>
     */
    private function egressCounted(FakeTelemetry $telemetry): array
    {
        $counted = [];

        foreach ($telemetry->counters() as $counter) {
            $counted[] = [$counter->attributes->get(EgressGateway::HOST_CLASS), $counter->attributes->get(EgressGateway::OUTCOME)];
        }

        return $counted;
    }
}
