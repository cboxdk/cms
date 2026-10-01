---
title: The delivery API
weight: 27
description: "GET /v1/resolve: what a site shows at a path, as the record DTO of the entry's type, for anyone; its statuses, content keys and cache headers, the fragments it is served from, and the explanation for staff."
---

# The delivery API

The delivery API answers what a site shows at a path, in a language, for anyone (PRD 8.9, 8.10, 8.12, MILESTONES M1 point 5). An application registers its route with `Cbox\Cms\Http\Delivery\DeliveryRoutes::register($router)` outside the web middleware group, so no session starts and no cookie is set on an answer; the workbench does it in `workbench/routes/api.php`.

## The request

`GET /v1/resolve?site=<host>&locale=<locale>&path=<path>`

- `site` is the host the page is requested at, such as `north.example`. Only a host of a site in [`cbox-cms.sites`](configuration.md#sites) resolves; any other is answered with [`host_not_configured`](../reference/errors.md#host_not_configured), 421, before anything is read. The request's own `Host` and `X-Forwarded-Host` play no part.
- `locale` is the language, such as `da`, and `path` the path, such as `/nyheder/harbour`, without a trailing slash.
- `debug=1` asks for the explanation; see below.

No other parameter and no header changes the answer (PRD 8.10 point 8). A parameter that is missing or malformed is [`validation_failed`](../reference/errors.md#validation_failed), 422, with an error per parameter.

## The answers

The controller holds no logic. The action `Cbox\Cms\Core\Delivery\Actions\DeliverPath` runs `path.resolve` through the query pipeline as the anonymous principal, with a pipeline of its own whose authorizer allows `path.resolve` for anyone and no other read (invariant 25). Row level security under the anonymous context decides what the read reaches.

| Status | When | Body |
|---|---|---|
| 200 | the path resolves to a visible placement | `{"data":<record>,"meta":{"canonical_url":...,"contract":1,"locale":...,"type":...}}`, a document of [`delivery.v1.json`](../addons/delivery-json.md) |
| 404 | nothing answers the path now: no site, locale, route or placement, a type without URLs, or a placement that is hidden, not yet or no longer in its window | problem details, [`path_not_found`](../reference/errors.md#path_not_found) |
| 410 | the variant or the placement was withdrawn, or the entry tombstoned or purged | problem details, [`path_gone`](../reference/errors.md#path_gone) |
| 421 | no site is served at the host | problem details, [`host_not_configured`](../reference/errors.md#host_not_configured) |

`data` is the record DTO of the entry's type, written by its generated codec through [`RecordCodecs`](../addons/contracts/record-codecs.md) at the public classification access, so no field above public is ever in the body (invariant 10). `canonical_url` is built from the origin of the canonical placement's site, never from the request.

## Content keys and cache headers

- `Surrogate-Key` holds the answer's content keys, `e-{entry}` and `n-{node}` (PRD 9.4): the entry's and the node it is placed under, and for a 404 below a route the node the slug was looked up under. 404 and 410 carry them too, so a publication or a new placement purges them (PRD 8.10 point 10). The edge strips the header before the answer leaves it (point 4).
- `Cache-Control` is `public, max-age=0, s-maxage=<seconds>` for an answer the edge may keep. The seconds are the lower of `cbox-cms.delivery.max_age_seconds` and the time until a placement's window next changes the answer, its end for a visible placement and its start for one that has not opened (invariant 17), the same cap for 200, 404 and 410. A 200 whose window never ends also gets `stale-while-revalidate` and `stale-if-error`; an answer before a removal, and every problem, gets neither (PRD 8.12 points 3 and 4).
- `private, no-store` is sent for 421, for a malformed request, for a build under a purge fence, and for the explanation.
- `Cbox-Cache` is `hit` for an answer served from a fragment and `miss` for one built at the origin. No `Vary` is sent.

## Fragments

An answer the edge may keep is stored in the [`FragmentStore`](../addons/contracts/fragment-store.md) under a key of the host, the locale and the path, as a document of [`delivery-fragment.v1.json`](../addons/delivery-json.md), with its content keys, the position of the read it was built from and its lifetime. The next request for the same key is served from the fragment, without the query pipeline and without a query, for what is left of that lifetime. When a dependency was purged at or above the read's position, the store refuses the fragment, and the answer is sent with `no-store` (PRD 8.12 point 1). The invalidation subscriber purges the fragments of an entry when it changes; see [subscribers](../addons/subscribers.md#the-kernels-invalidation-subscriber).

## The explanation

With `debug=1` the read runs as the principal of the request's Bearer credential, and the answer is `{"data":...,"explanation":{...},"meta":...,"problem":...,"status":...}`, a document of [`delivery-explanation.v1.json`](../addons/delivery-json.md): the record or the problem, and every step of the resolution, the site, the route, the node, a mount, the placement, the visibility decision with its valid_until, and the canonical placement. Only an actor whose classification access is at least internal gets it; anyone else is answered with [`unauthorized`](../reference/errors.md#unauthorized). The explanation is never stored and always sent with `private, no-store`.
