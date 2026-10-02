<?php

declare(strict_types=1);

use Cbox\Cms\Core\Egress\Domain\Dto\EgressHeader;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressRequest;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressResponse;
use Cbox\Cms\Core\Egress\Domain\EgressFailed;
use Cbox\Cms\Core\Egress\Domain\EgressGateway;
use Cbox\Cms\Core\Egress\Domain\HostClass;
use Cbox\Ssrf\Contracts\Resolver;
use Cbox\Ssrf\Testing\FakeResolver;
use Illuminate\Http\Client\Factory;

// An addon fetches an oEmbed document from a URL an editor pasted. It asks the container for the
// egress gateway and sends a GET through it; the SSRF guard refuses a URL whose host resolves to
// the cloud metadata address. The test fakes DNS with laravel-ssrf's FakeResolver and Laravel's
// HTTP client with fake(), so nothing leaves the test.

it('fetches a public https URL and refuses one whose DNS points at the metadata address', function (): void {
    app()->instance(Resolver::class, new FakeResolver([
        'media.example.com' => ['93.184.215.14'],
        'evil.example.net' => ['169.254.169.254'],
    ]));
    app(Factory::class)->fake([
        'https://media.example.com/*' => Factory::response('{"title":"Harbour at dawn"}'),
    ]);
    // What the addon does: send a GET through the EgressGateway the container gives.
    $oembed = static fn (EgressGateway $gateway, string $url): EgressResponse => $gateway->get(new EgressRequest(
        new HostClass('oembed'),
        $url,
        [new EgressHeader('Accept', 'application/json')],
    ));

    $response = $oembed(app(EgressGateway::class), 'https://media.example.com/oembed?url=https%3A%2F%2Fmedia.example.com%2Fp%2F1');

    expect($response->status)->toBe(200)
        ->and($response->body)->toBe('{"title":"Harbour at dawn"}')
        ->and(fn (): EgressResponse => $oembed(app(EgressGateway::class), 'https://evil.example.net/oembed'))
        ->toThrow(EgressFailed::class, 'refused by the SSRF guard');
});
