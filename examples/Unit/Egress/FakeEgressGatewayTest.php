<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Egress\EgressFailed;
use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Contracts\Egress\EgressRequest;
use Cbox\Cms\Contracts\Egress\EgressResponse;
use Cbox\Cms\Contracts\Egress\HostClass;
use Cbox\Cms\Testkit\Egress\FakeEgressGateway;

// An addon reads the title of a media URL from its oEmbed endpoint through the egress gateway the
// container gives. The test binds the testkit's FakeEgressGateway in its place, so nothing leaves
// the test: it answers the endpoint, refuses a host as the SSRF guard would, and records each
// request the addon sent.

it('reads the oEmbed title through the gateway and keeps a refused host out', function (): void {
    $gateway = (new FakeEgressGateway)
        ->answer('https://media.example.com/oembed', new EgressResponse(200, '{"title":"Harbour at dawn"}'))
        ->block('intranet.example.com');
    app()->instance(EgressGateway::class, $gateway);
    // What the addon does: a GET through the EgressGateway the container gives, null when refused.
    $title = static function (string $endpoint): ?string {
        try {
            $response = app(EgressGateway::class)->get(new EgressRequest(new HostClass('oembed'), $endpoint));
        } catch (EgressFailed) {
            return null;
        }

        $document = json_decode($response->body, true);

        return is_array($document) && is_string($document['title'] ?? null) ? $document['title'] : null;
    };

    expect($title('https://media.example.com/oembed'))->toBe('Harbour at dawn')
        ->and($title('https://intranet.example.com/oembed'))->toBeNull()
        ->and(array_map(static fn (EgressRequest $request): string => $request->url, $gateway->requests()))
        ->toBe(['https://media.example.com/oembed', 'https://intranet.example.com/oembed']);
});
