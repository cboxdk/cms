<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Egress\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressRequest;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressResponse;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressSettings;
use Cbox\Cms\Core\Egress\Domain\EgressFailed;
use Cbox\Cms\Core\Egress\Domain\EgressGateway;
use Cbox\Cms\Core\Egress\Domain\EgressOutcome;
use Cbox\Cms\Core\Egress\Domain\HostClass;
use Cbox\Ssrf\Exceptions\BlockedUrl;
use Cbox\Ssrf\GuardPolicy;
use Cbox\Ssrf\Http\GuardRequestMiddleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Override;

/**
 * The egress gateway on Laravel's HTTP client and cboxdk/laravel-ssrf, used as released
 * (GUARDRAILS 3).
 *
 * Each request is built as the package's Http::ssrf() builds it: redirects off, and the package's
 * GuardRequestMiddleware, which checks the URL the request is actually sent to when it is sent and
 * pins the connection to the addresses it checked (CURLOPT_RESOLVE), here with https as the only
 * scheme and no credentials in the URL. The policy is the package's, `ssrf` in the configuration;
 * the gateway sends nothing unless it enforces and pins DNS. The timeouts are cbox-cms.egress's.
 */
#[Internal]
final readonly class SsrfEgressGateway implements EgressGateway
{
    /** The only scheme the gateway sends to. */
    public const array SCHEMES = ['https'];

    public const string REQUESTS = 'cms.egress.requests';

    public const string FAILURES = 'cms.egress.failures';

    public const string HOST_CLASS = 'cms.egress.host_class';

    public const string OUTCOME = 'cms.egress.outcome';

    public function __construct(
        private Factory $http,
        private GuardPolicy $policy,
        private EgressSettings $settings,
        private Telemetry $telemetry,
    ) {}

    #[Override]
    public function get(EgressRequest $request): EgressResponse
    {
        try {
            $response = $this->send($request);
        } catch (EgressFailed $failed) {
            $this->count($request->hostClass, $failed->outcome);

            throw $failed;
        }

        $this->count($request->hostClass, $response->outcome());

        return $response;
    }

    private function send(EgressRequest $request): EgressResponse
    {
        if (! $this->policy->enforce || ! $this->policy->pinDns) {
            throw EgressFailed::guardDisabled($request->hostClass);
        }

        $headers = [];

        foreach ($request->headers as $header) {
            $headers[$header->name] = $header->value;
        }

        try {
            $response = $this->http
                ->withOptions(['allow_redirects' => false])
                ->withMiddleware(new GuardRequestMiddleware(self::SCHEMES, false))
                ->connectTimeout($this->settings->connectTimeoutMilliseconds / 1000)
                ->timeout($this->settings->timeoutMilliseconds / 1000)
                ->withHeaders($headers)
                ->get($request->url);
        } catch (BlockedUrl) {
            throw EgressFailed::blocked($request->hostClass);
        } catch (ConnectionException) {
            throw EgressFailed::unavailable($request->hostClass);
        }

        $status = $response->status();

        if ($status < 100 || $status > 599) {
            throw EgressFailed::unavailable($request->hostClass);
        }

        if ($status >= 300 && $status < 400) {
            throw EgressFailed::redirect($request->hostClass, $status);
        }

        return new EgressResponse($status, $response->body());
    }

    private function count(HostClass $hostClass, EgressOutcome $outcome): void
    {
        $attributes = new Attributes(Attribute::of(self::HOST_CLASS, $hostClass->value), Attribute::of(self::OUTCOME, $outcome->value));

        $this->telemetry->counter(new CounterRecord(new TelemetryName(self::REQUESTS), 1, $attributes));

        if ($outcome->failed()) {
            $this->telemetry->counter(new CounterRecord(new TelemetryName(self::FAILURES), 1, $attributes));
        }
    }
}
