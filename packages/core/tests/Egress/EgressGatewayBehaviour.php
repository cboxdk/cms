<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Core\Egress\Adapter\SsrfEgressGateway;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressHeader;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressRequest;
use Cbox\Cms\Core\Egress\Domain\EgressFailed;
use Cbox\Cms\Core\Egress\Domain\EgressGateway;
use Cbox\Cms\Core\Egress\Domain\HostClass;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every EgressGateway does, run against SsrfEgressGateway and FakeEgressGateway (GUARDRAILS
 * 9): it hands on every answer but a redirect, refuses a redirect, a destination the guard blocks
 * and a scheme other than https, fails as unavailable when nothing answers, sends the headers it is
 * given, and counts every request and every failure under the host class and the outcome.
 */
trait EgressGatewayBehaviour
{
    /**
     * A gateway that counts on the given telemetry.
     */
    abstract protected function gateway(FakeTelemetry $telemetry): EgressGateway;

    /**
     * The URL, on a host that resolves to a public address, answers with the status and body.
     */
    abstract protected function answer(string $url, int $status, string $body = ''): void;

    /**
     * The host resolves to a private address, which the guard refuses.
     */
    abstract protected function pointAtPrivateAddress(string $host): void;

    /**
     * The URL's destination does not answer.
     */
    abstract protected function goDown(string $url): void;

    /**
     * The value of the header in the last request sent, or null.
     */
    abstract protected function sentHeader(string $name): ?string;

    #[Test]
    public function it_hands_on_every_answer_but_a_redirect_and_counts_it(): void
    {
        $telemetry = new FakeTelemetry;
        $gateway = $this->gateway($telemetry);
        $this->answer('https://api.example.com/ok', 200, 'fine');
        $this->answer('https://api.example.com/missing', 404, 'nothing here');

        $ok = $gateway->get($this->request('https://api.example.com/ok', [new EgressHeader('Add-Padding', 'true')]));
        $missing = $gateway->get($this->request('https://api.example.com/missing'));

        Assert::assertSame([200, 'fine'], [$ok->status, $ok->body]);
        Assert::assertSame([404, 'nothing here'], [$missing->status, $missing->body]);
        Assert::assertSame(2, $telemetry->counted(SsrfEgressGateway::REQUESTS));
        Assert::assertSame(1, $telemetry->counted(SsrfEgressGateway::FAILURES));
        Assert::assertSame([['probe', 'ok'], ['probe', 'status'], ['probe', 'status']], $this->counted($telemetry));
    }

    #[Test]
    public function it_sends_the_headers_it_is_given(): void
    {
        $gateway = $this->gateway(new FakeTelemetry);
        $this->answer('https://api.example.com/range/ABCDE', 200, 'x');

        $gateway->get($this->request('https://api.example.com/range/ABCDE', [new EgressHeader('Add-Padding', 'true')]));

        Assert::assertSame('true', $this->sentHeader('Add-Padding'));
    }

    #[Test]
    public function it_refuses_a_redirect(): void
    {
        $telemetry = new FakeTelemetry;
        $gateway = $this->gateway($telemetry);
        $this->answer('https://api.example.com/moved', 302);

        $failed = $this->failure(fn () => $gateway->get($this->request('https://api.example.com/moved')));

        Assert::assertSame(EgressFailed::CODE_REDIRECT_REFUSED, $failed->errorCode);
        Assert::assertSame([['probe', 'redirect'], ['probe', 'redirect']], $this->counted($telemetry));
    }

    #[Test]
    public function it_refuses_a_destination_the_guard_blocks_and_a_scheme_other_than_https(): void
    {
        $telemetry = new FakeTelemetry;
        $gateway = $this->gateway($telemetry);
        $this->pointAtPrivateAddress('intranet.example.com');
        $this->answer('https://api.example.com/plain', 200, 'fine');

        $private = $this->failure(fn () => $gateway->get($this->request('https://intranet.example.com/admin')));
        $plain = $this->failure(fn () => $gateway->get($this->request('http://api.example.com/plain')));

        Assert::assertSame(EgressFailed::CODE_BLOCKED, $private->errorCode);
        Assert::assertSame(EgressFailed::CODE_BLOCKED, $plain->errorCode);
        Assert::assertSame(2, $telemetry->counted(SsrfEgressGateway::FAILURES));
    }

    #[Test]
    public function it_fails_as_unavailable_when_nothing_answers(): void
    {
        $telemetry = new FakeTelemetry;
        $gateway = $this->gateway($telemetry);
        $this->goDown('https://api.example.com/down');

        $failed = $this->failure(fn () => $gateway->get($this->request('https://api.example.com/down')));

        Assert::assertSame(EgressFailed::CODE_UNAVAILABLE, $failed->errorCode);
        Assert::assertSame([['probe', 'unavailable'], ['probe', 'unavailable']], $this->counted($telemetry));
    }

    /**
     * @param  list<EgressHeader>  $headers
     */
    private function request(string $url, array $headers = []): EgressRequest
    {
        return new EgressRequest(new HostClass('probe'), $url, $headers);
    }

    /**
     * @param  callable(): mixed  $send
     */
    private function failure(callable $send): EgressFailed
    {
        try {
            $send();
        } catch (EgressFailed $failed) {
            Assert::assertStringNotContainsString('example.com', $failed->getMessage());

            return $failed;
        }

        Assert::fail('The request did not fail.');
    }

    /**
     * Each counter's host class and outcome, in the order counted.
     *
     * @return list<array{0: string|int|float|bool|null, 1: string|int|float|bool|null}>
     */
    private function counted(FakeTelemetry $telemetry): array
    {
        $counted = [];

        foreach ($telemetry->counters() as $counter) {
            $counted[] = [$counter->attributes->get(SsrfEgressGateway::HOST_CLASS), $counter->attributes->get(SsrfEgressGateway::OUTCOME)];
        }

        return $counted;
    }
}
