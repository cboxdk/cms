<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress\Fakes;

use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Core\Egress\Adapter\SsrfEgressGateway;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressRequest;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressResponse;
use Cbox\Cms\Core\Egress\Domain\EgressFailed;
use Cbox\Cms\Core\Egress\Domain\EgressGateway;
use Cbox\Cms\Core\Egress\Domain\EgressOutcome;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Closure;
use Override;

/**
 * The fake of the egress gateway, held to SsrfEgressGateway by EgressGatewayBehaviour. It sends
 * nothing: it answers each URL as answer() set it, a URL whose host block() named or whose scheme
 * is not https is refused as the guard refuses it, after goDown() nothing answers, a URL without an
 * answer of its own gets answerOthers()'s, and a URL it has no answer for is unavailable. A redirect fails as the gateway's does. It records every request,
 * and counts as the gateway counts.
 */
final class FakeEgressGateway implements EgressGateway
{
    /** @var array<string, (Closure(EgressRequest): (EgressResponse|int))|EgressResponse|int> */
    private array $answers = [];

    /** @var (Closure(EgressRequest): (EgressResponse|int))|null */
    private ?Closure $otherwise = null;

    /** @var array<string, true> */
    private array $blocked = [];

    private bool $down = false;

    /** @var list<EgressRequest> */
    private array $requests = [];

    public function __construct(private readonly Telemetry $telemetry = new FakeTelemetry) {}

    /**
     * The URL answers with the response, a redirect status, or what the closure gives for the request.
     *
     * @param  (Closure(EgressRequest): (EgressResponse|int))|EgressResponse|int  $answer
     */
    public function answer(string $url, Closure|EgressResponse|int $answer): self
    {
        $this->answers[$url] = $answer;

        return $this;
    }

    /**
     * Every URL without an answer of its own answers with what the closure gives for the request.
     *
     * @param  Closure(EgressRequest): (EgressResponse|int)  $answer
     */
    public function answerOthers(Closure $answer): self
    {
        $this->otherwise = $answer;

        return $this;
    }

    public function block(string $host): self
    {
        $this->blocked[strtolower($host)] = true;

        return $this;
    }

    public function goDown(): self
    {
        $this->down = true;

        return $this;
    }

    /**
     * @return list<EgressRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    #[Override]
    public function get(EgressRequest $request): EgressResponse
    {
        $this->requests[] = $request;

        try {
            $response = $this->respond($request);
        } catch (EgressFailed $failed) {
            $this->count($request, $failed->outcome);

            throw $failed;
        }

        $this->count($request, $response->outcome());

        return $response;
    }

    private function respond(EgressRequest $request): EgressResponse
    {
        $scheme = parse_url($request->url, PHP_URL_SCHEME);
        $host = parse_url($request->url, PHP_URL_HOST);

        if ($scheme !== 'https' || ! is_string($host) || isset($this->blocked[strtolower($host)])) {
            throw EgressFailed::blocked($request->hostClass);
        }

        $answer = $this->answers[$request->url] ?? $this->otherwise;

        if ($this->down || $answer === null) {
            throw EgressFailed::unavailable($request->hostClass);
        }

        $answer = $answer instanceof Closure ? $answer($request) : $answer;

        if (is_int($answer)) {
            throw EgressFailed::redirect($request->hostClass, $answer);
        }

        return $answer;
    }

    private function count(EgressRequest $request, EgressOutcome $outcome): void
    {
        $attributes = new Attributes(
            Attribute::of(SsrfEgressGateway::HOST_CLASS, $request->hostClass->value),
            Attribute::of(SsrfEgressGateway::OUTCOME, $outcome->value),
        );

        $this->telemetry->counter(new CounterRecord(new TelemetryName(SsrfEgressGateway::REQUESTS), 1, $attributes));

        if ($outcome->failed()) {
            $this->telemetry->counter(new CounterRecord(new TelemetryName(SsrfEgressGateway::FAILURES), 1, $attributes));
        }
    }
}
