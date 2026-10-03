---
title: Egress gateway
weight: 48
description: "The EgressGateway contract: the one way out for outbound HTTP, its default SsrfEgressGateway behind the SSRF guard, how an application replaces it, the testkit's FakeEgressGateway and the shared suite EgressGatewayContract with its harness."
---

# Egress gateway

<!-- extension-point: Cbox\Cms\Contracts\Egress\EgressGateway -->
<!-- extension-point: Cbox\Cms\Testkit\Egress\EgressGatewayHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Egress\EgressGatewayContract -->

Every outbound HTTP request of the kernel, its modules and its addons goes through `Cbox\Cms\Contracts\Egress\EgressGateway` (GUARDRAILS 3). It is `#[Experimental]`. What the gateway refuses, its timeouts, its error codes and its counters are on [Egress](../../security/egress.md).

## The contract

`get(EgressRequest $request): EgressResponse` sends a GET over https to the request's URL with its headers. A request is a `HostClass`, a lowercase word for the kind of destination such as `oembed`, the URL and a list of `EgressHeader`s. An implementation checks the destination before it connects and refuses a private, reserved or metadata address, a scheme other than https and credentials in the URL; it never follows a redirect. It returns the response for every status but a redirect, and otherwise throws `EgressFailed` with an `errorCode` of the catalog, whose message never holds the URL and which chains no exception. It counts every request under `EgressGateway::REQUESTS`, and every one that does not end with a `2xx` status under `EgressGateway::FAILURES`, each with the attributes `EgressGateway::HOST_CLASS` and `EgressGateway::OUTCOME` (an `EgressOutcome`) only.

## The default and replacing it

`cbox-cms.contracts` binds the contract to the core's `Cbox\Cms\Core\Egress\Adapter\SsrfEgressGateway`, on Laravel's HTTP client and cboxdk/laravel-ssrf, unless the application names another class; see [Configuration](../../developers/configuration.md#contracts). A replacement passes the shared suite below. The architecture tests of `cboxdk/cms` allow an HTTP client only in the core's adapter, so a replacement lives in the application or an addon, and checks every destination before it connects, as the suite requires.

## The fake: FakeEgressGateway

`Cbox\Cms\Testkit\Egress\FakeEgressGateway` sends nothing. `answer($url, $response)` sets the answer of a URL, a response, a redirect status or a closure of the request, and `answerOthers()` the answer of every other URL; `block($host)` makes a host refused as the guard refuses it, and so is every scheme but https; after `goDown()` nothing answers, and a URL without an answer is unavailable. `requests()` lists every request it took, in order, and it counts on the telemetry it is given as a gateway counts. This example is in the `Unit` suite:

<!-- example: examples/Unit/Egress/FakeEgressGatewayTest.php -->
```php
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
```

## Running the shared suite against an implementation

Every implementation runs the shared suite, the trait `Cbox\Cms\Testkit\Egress\EgressGatewayContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `harness(): EgressGatewayHarness`, which returns a fresh harness for each case. The harness gives the network's side: `gateway(FakeTelemetry $telemetry)` is the implementation under test counting on that telemetry, `answer($url, $status, $body)` makes a URL on `api.example.com`, which resolves to a public address, answer, `pointAtPrivateAddress($host)` makes a host resolve to a private address, `goDown($url)` makes a destination stop answering, and `sentHeader($name)` reads a header of the last request that reached the transport. A harness for a real gateway fakes the transport and DNS and keeps the gateway's own guard; the core's harness for `SsrfEgressGateway` fakes Laravel's HTTP client and answers DNS with laravel-ssrf's `FakeResolver`.

The cases cover answers that are handed on, the headers sent, a refused redirect, a refused private address and plain http, a destination that does not answer, failures that never name the URL, and the counters of each.
