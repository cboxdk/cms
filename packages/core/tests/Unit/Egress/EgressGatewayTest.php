<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Unit\Egress;

use Cbox\Cms\Core\Egress\Adapter\SsrfEgressGateway;
use Cbox\Cms\Core\Egress\Boundary\EgressConfig;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressRequest;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressResponse;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressSettings;
use Cbox\Cms\Core\Egress\Domain\EgressFailed;
use Cbox\Cms\Core\Egress\Domain\EgressGateway;
use Cbox\Cms\Core\Egress\Domain\HostClass;
use Cbox\Cms\Core\Tests\Egress\SsrfEgressGatewayWorld;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Ssrf\GuardPolicy;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use InvalidArgumentException;

// The egress gateway (GUARDRAILS 3, PRD 7.14) on laravel-ssrf, with its transport faked and DNS
// answered by the package's FakeResolver: what it refuses, that it never follows a redirect, and
// what it counts.

/**
 * The failure of a request through the gateway, or null when it was answered.
 */
function egressFailure(EgressGateway $gateway, string $url): ?EgressFailed
{
    try {
        $gateway->get(new EgressRequest(new HostClass('probe'), $url));

        return null;
    } catch (EgressFailed $failed) {
        return $failed;
    }
}

/**
 * Each counter as name, host class and outcome.
 *
 * @return list<string>
 */
function egressCounters(FakeTelemetry $telemetry): array
{
    $counted = [];

    foreach ($telemetry->counters() as $counter) {
        $counted[] = sprintf('%s %s %s', $counter->name->value, (string) $counter->attributes->get(SsrfEgressGateway::HOST_CLASS), (string) $counter->attributes->get(SsrfEgressGateway::OUTCOME));
    }

    return $counted;
}

it('refuses the loopback address and the cloud metadata address before connecting, and counts each failure', function (string $url): void {
    $world = new SsrfEgressGatewayWorld(app());
    $telemetry = new FakeTelemetry;
    $world->answer($url, 200, 'secrets');

    $failed = egressFailure($world->gateway($telemetry), $url);

    expect($failed?->errorCode)->toBe(EgressFailed::CODE_BLOCKED)
        ->and((string) $failed?->getMessage())->not->toContain('127.0.0.1')
        ->and((string) $failed?->getMessage())->not->toContain('169.254.169.254')
        ->and((string) $failed?->getMessage())->not->toContain('meta-data')
        ->and($world->sent())->toBe([])
        ->and(egressCounters($telemetry))->toBe(['cms.egress.requests probe blocked', 'cms.egress.failures probe blocked']);
})->with([
    'loopback' => ['https://127.0.0.1/admin'],
    'cloud metadata' => ['https://169.254.169.254/latest/meta-data/iam/security-credentials/'],
    'IPv4-mapped loopback' => ['https://[::ffff:127.0.0.1]/'],
]);

it('refuses a host whose DNS points at the metadata address or a private network', function (string $address): void {
    $world = new SsrfEgressGatewayWorld(app());
    $world->resolver->set('rebind.example.net', [$address]);
    $world->answer('https://rebind.example.net/', 200, 'secrets');
    $telemetry = new FakeTelemetry;

    expect(egressFailure($world->gateway($telemetry), 'https://rebind.example.net/')?->errorCode)->toBe(EgressFailed::CODE_BLOCKED)
        ->and($world->sent())->toBe([])
        ->and($telemetry->counted(SsrfEgressGateway::FAILURES))->toBe(1);
})->with(['169.254.169.254', '10.0.0.5', '192.168.1.1', '127.0.0.1']);

it('refuses a redirect and never follows it, even to a public address', function (int $status): void {
    $world = new SsrfEgressGatewayWorld(app());
    $world->http->fake([
        'https://api.example.com/moved' => Factory::response('', $status, ['Location' => 'https://169.254.169.254/latest/meta-data/']),
    ]);
    $telemetry = new FakeTelemetry;

    $failed = egressFailure($world->gateway($telemetry), 'https://api.example.com/moved');

    expect($failed?->errorCode)->toBe(EgressFailed::CODE_REDIRECT_REFUSED)
        ->and($failed?->getMessage())->toContain((string) $status)
        ->and(array_map(static fn (Request $request): string => $request->url(), $world->sent()))->toBe(['https://api.example.com/moved'])
        ->and(egressCounters($telemetry))->toBe(['cms.egress.requests probe redirect', 'cms.egress.failures probe redirect']);
})->with([301, 302, 303, 307, 308]);

it('refuses another scheme than https and credentials in the URL', function (string $url): void {
    $world = new SsrfEgressGatewayWorld(app());
    $world->answer($url, 200);

    expect(egressFailure($world->gateway(new FakeTelemetry), $url)?->errorCode)->toBe(EgressFailed::CODE_BLOCKED)
        ->and($world->sent())->toBe([]);
})->with(['http://api.example.com/', 'ftp://api.example.com/', 'file:///etc/passwd', 'gopher://api.example.com/', 'https://user:secret@api.example.com/']);

it('sends nothing when the guard does not enforce or does not pin DNS', function (GuardPolicy $policy): void {
    $world = new SsrfEgressGatewayWorld(app());
    $world->answer('https://api.example.com/', 200);
    $telemetry = new FakeTelemetry;

    $failed = $world->gateway($telemetry, $policy)->get(...);

    expect(fn (): EgressResponse => $failed(new EgressRequest(new HostClass('probe'), 'https://api.example.com/')))->toThrow(EgressFailed::class, 'ssrf.enforce and ssrf.pin_dns')
        ->and($world->sent())->toBe([])
        ->and(egressCounters($telemetry))->toBe(['cms.egress.requests probe guard_disabled', 'cms.egress.failures probe guard_disabled']);
})->with([
    'not enforcing' => [new GuardPolicy(enforce: false)],
    'not pinning' => [new GuardPolicy(pinDns: false)],
]);

it('sends with the timeouts of cbox-cms.egress, redirects off and the connection pinned to the address it checked', function (): void {
    $world = new SsrfEgressGatewayWorld(app());
    $seen = [];
    $world->http->fake(static function (Request $request, array $options) use (&$seen): mixed {
        $seen = $options;

        return Factory::response('ok');
    });
    $gateway = new SsrfEgressGateway($world->http, app(GuardPolicy::class), new EgressSettings(1500, 4000), new FakeTelemetry);

    $gateway->get(new EgressRequest(new HostClass('probe'), 'https://api.example.com/range/ABCDE?x=1'));

    expect($seen['connect_timeout'] ?? null)->toBe(1.5)
        ->and($seen['timeout'] ?? null)->toEqual(4)
        ->and($seen['allow_redirects'] ?? null)->toBeFalse()
        ->and(is_array($seen['curl'] ?? null) ? $seen['curl'][CURLOPT_RESOLVE] ?? null : null)->toContain('api.example.com:443:'.SsrfEgressGatewayWorld::PUBLIC_ADDRESS);
});

it('counts under the host class and the outcome only, never the host, the path or the query', function (): void {
    $world = new SsrfEgressGatewayWorld(app());
    $world->answer('https://api.example.com/range/ABCDE?token=secret', 503, 'busy');
    $telemetry = new FakeTelemetry;

    $response = $world->gateway($telemetry)->get(new EgressRequest(new HostClass('probe'), 'https://api.example.com/range/ABCDE?token=secret'));

    expect($response->status)->toBe(503)
        ->and(egressCounters($telemetry))->toBe(['cms.egress.requests probe status', 'cms.egress.failures probe status']);

    foreach ($telemetry->counters() as $counter) {
        expect($counter->attributes->names())->toBe([SsrfEgressGateway::HOST_CLASS, SsrfEgressGateway::OUTCOME]);
    }
});

it('is the gateway the container gives, with the timeouts of the configuration', function (): void {
    config()->set('cbox-cms.egress.timeout_ms', 7000);

    expect(app(EgressGateway::class))->toBeInstanceOf(SsrfEgressGateway::class)
        ->and(app(EgressSettings::class))->toEqual(new EgressSettings(2000, 7000));
});

it('reads the timeouts with PRD 7.14\'s defaults and refuses a timeout out of range', function (): void {
    expect(EgressConfig::read(new Repository([])))->toEqual(new EgressSettings(2000, 10_000))
        ->and(EgressConfig::read(new Repository(['cbox-cms' => ['egress' => ['connect_timeout_ms' => 500, 'timeout_ms' => 500]]])))->toEqual(new EgressSettings(500, 500));

    foreach ([
        ['connect_timeout_ms' => '2000'],
        ['connect_timeout_ms' => 0],
        ['connect_timeout_ms' => 30_001],
        ['connect_timeout_ms' => 3000, 'timeout_ms' => 2999],
        ['timeout_ms' => 60_001],
        ['timeout_ms' => null],
    ] as $egress) {
        expect(fn (): EgressSettings => EgressConfig::read(new Repository(['cbox-cms' => ['egress' => $egress]])))->toThrow(InvalidArgumentException::class, 'cbox-cms.egress');
    }
});
