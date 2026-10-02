<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Egress;

use Cbox\Cms\Core\Egress\Adapter\SsrfEgressGateway;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressSettings;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Ssrf\Contracts\Resolver;
use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Ssrf\GuardPolicy;
use Cbox\Ssrf\Testing\FakeResolver;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

/**
 * The real gateway with nothing real outside it: Laravel's HTTP client faked, so no request leaves
 * the test, and DNS answered by laravel-ssrf's FakeResolver, so the guard checks and pins the
 * addresses a test names. The guard, its middleware and the policy are the package's, from the
 * container. api.example.com resolves to a public address.
 */
final class SsrfEgressGatewayWorld
{
    public const string PUBLIC_ADDRESS = '93.184.215.14';

    public readonly Factory $http;

    public readonly FakeResolver $resolver;

    /** @var array<string, Closure(Request): mixed> */
    private array $answers = [];

    public function __construct(private readonly Application $app)
    {
        $this->resolver = new FakeResolver(['api.example.com' => [self::PUBLIC_ADDRESS]]);
        $app->instance(Resolver::class, $this->resolver);
        $app->forgetInstance(UrlGuard::class);

        $this->http = new Factory;
        $this->http->preventStrayRequests();
        $this->http->fake(fn (Request $request): mixed => $this->answerTo($request));
    }

    public function gateway(FakeTelemetry $telemetry, ?GuardPolicy $policy = null): SsrfEgressGateway
    {
        return new SsrfEgressGateway($this->http, $policy ?? $this->app->make(GuardPolicy::class), new EgressSettings(2000, 10_000), $telemetry);
    }

    public function answer(string $url, int $status, string $body = ''): void
    {
        $this->answers[$url] = static fn (): mixed => Factory::response($body, $status);
    }

    public function goDown(string $url): void
    {
        $this->answers[$url] = Factory::failedConnection();
    }

    /**
     * The requests that reached the faked transport, past the guard.
     *
     * @return list<Request>
     */
    public function sent(): array
    {
        return array_values(array_map(static fn (array $pair): Request => $pair[0], $this->http->recorded()->all()));
    }

    private function answerTo(Request $request): mixed
    {
        $answer = $this->answers[$request->url()] ?? null;

        return $answer === null ? null : $answer($request);
    }
}
