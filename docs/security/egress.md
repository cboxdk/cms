---
title: Egress
weight: 53
description: "The egress gateway: every outbound request goes through it and its SSRF guard on cboxdk/laravel-ssrf, what it refuses, its timeouts and counters, how the architecture tests hold the rule, and why PHP runs with allow_url_fopen off."
---

# Egress

A CMS fetches URLs that people and other systems give it, which makes it a way into the network it runs in. The rule is therefore that every outbound request goes through one gateway, the namespace `Cbox\Cms\Core\Egress`, which checks the destination with an SSRF guard before it connects (GUARDRAILS 3).

<!-- extension-point: Cbox\Cms\Core\Egress\Domain\EgressGateway -->

## The gateway

`Cbox\Cms\Core\Egress\Domain\EgressGateway` has one method, `get(EgressRequest $request): EgressResponse`. A request is a `HostClass`, the URL and a list of `EgressHeader`s. The host class is a lowercase word the caller chooses for the kind of destination, such as `breached_passwords` or `oembed`; the gateway counts under it, so telemetry never carries the URL. The interface is `#[Experimental]`: a module or an addon asks the container for it, and the container gives `SsrfEgressGateway`.

The gateway is built on [cboxdk/laravel-ssrf](https://github.com/cboxdk/laravel-ssrf), used as released. Every request is sent the way the package's `Http::ssrf()` sends it: the package's middleware checks the URL the request is actually sent to, at the moment it is sent, and pins the connection to the addresses it checked, so DNS cannot point it elsewhere between the check and the connection. The gateway:

- sends only `https`, and refuses credentials in the URL;
- refuses loopback, private, link-local, reserved, multicast and cloud metadata addresses, such as `127.0.0.1`, `10.0.0.0/8` and `169.254.169.254`, also as IPv6 transition forms and when a host name resolves to one, and blocked hosts such as `localhost` and names ending in `.internal`;
- never follows a redirect: a `3xx` answer fails with `egress_redirect_refused`;
- waits at most `cbox-cms.egress.connect_timeout_ms` (2 seconds) for a connection and `cbox-cms.egress.timeout_ms` (10 seconds) in all, PRD 7.14's values for a webhook delivery;
- sends nothing when the package's policy is switched off: `ssrf.enforce` and `ssrf.pin_dns` must both be on, or every request fails with `egress_guard_disabled`.

It returns the response for every status but a redirect, and the caller decides what a `404` or a `503` means. Otherwise it throws `EgressFailed`, whose `errorCode` is one of these, and whose message names the host class and the reason, never the URL:

| Code | When | Retry |
|---|---|---|
| [`egress_blocked`](../reference/errors.md#egress_blocked) | the guard refused the URL | no |
| [`egress_redirect_refused`](../reference/errors.md#egress_redirect_refused) | the destination answered with a redirect | no |
| [`egress_unavailable`](../reference/errors.md#egress_unavailable) | no connection, or no answer within the timeouts | yes |
| [`egress_guard_disabled`](../reference/errors.md#egress_guard_disabled) | the guard's policy is off | no |

This example is in the `Unit` suite; it fakes DNS with the package's `FakeResolver` and the HTTP client with Laravel's `fake()`:

<!-- example: examples/Unit/Egress/EgressGatewayTest.php -->
```php
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
```

## Counters

Every request adds 1 to `cms.egress.requests`, and every request that does not end with a `2xx` status adds 1 to `cms.egress.failures`, both through the [Telemetry](../addons/contracts/telemetry.md) contract. Each counter has two attributes and no others: `cms.egress.host_class` and `cms.egress.outcome`, which is `ok`, `status` (another status that is not a redirect), `redirect`, `blocked`, `unavailable` or `guard_disabled`. The host, the path and the query of the URL are never counted, because a URL can carry a token or personal data.

## What the rule covers

The architecture tests fail on every use of:

- HTTP clients: Guzzle, Laravel's `Http` facade and the other clients, and the `curl_*` functions;
- sockets: `fsockopen`, `pfsockopen`, `stream_socket_client`, the `socket_*` and `ftp_*` functions;
- every function that opens a file name, because PHP's URL wrappers let it fetch `http://`, `https://` and `ftp://`: `file_get_contents`, `fopen`, `file`, `readfile`, `copy`, `SplFileObject`, `DOMDocument` and `XMLReader` loading, and the others;
- the framework's services that reach other hosts: the mailers, notifications, the filesystem disks and the image manager;
- the functions that run a program, and Symfony and Illuminate Process, because a program can make its own requests.

A class may use one of these only when the architecture test lists it as an exception, with the names it may use and the reason; the gateway's namespace is no exception as a whole. The exceptions are the gateway's adapter, `SsrfEgressGateway`, with Laravel's HTTP client and nothing else; the classes that read and write the local files the kernel owns, such as the registry cache, the blueprint files and the generated code, each of which refuses a path that names a stream wrapper before it opens it; the doctor's probe that runs `node --version`; and the testkit's helpers for tests.

## allow_url_fopen

The PHP of every process that runs Cbox CMS has `allow_url_fopen` off, so a file function that the tests did not catch still cannot open a URL. `cms:doctor` checks it as `php.allow_url_fopen`, and the check blocks the kernel from starting. The php container of the development environment sets it in `docker/php/conf.d/cms.ini`.

## What the gateway does not do

The guard is defence in depth, not the whole answer, as the package itself says. A network rule that lets the servers reach only the destinations they need is what closes the door, and the cloud metadata address should be blocked at the network too. A proxy set with `HTTP_PROXY` resolves the names itself, so the pin to the checked addresses does not hold through it. The gateway controls where a request goes, not what comes back: a caller reads the body as untrusted input.
