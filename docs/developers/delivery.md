---
title: The delivery API
weight: 27
description: "GET /v1/resolve: what a site shows at a path, as the record DTO of the entry's type, for anyone; its statuses, the redirect, content keys and cache headers, the fragments it is served from, and the explanation for staff."
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
| 200 | the path resolves to a visible placement | `{"data":<record>,"meta":{"canonical_url":...,"contract":1,"fallback":...,"fallback_fields":[...],"locale":...,"resolved_locale":...,"type":...}}`, a document of [`delivery.v1.json`](../addons/delivery-json.md) |
| 301 | the page lives at another URL, in one hop: a mount URL of an entry the site has a placement of its own for (PRD 5.8) | no body; `Location` holds the absolute URL, built from the site's origin |
| 404 | nothing answers the path now: no site, locale, route or placement, a type without URLs, a placement that is hidden, not yet or no longer in its window, or, for a type with `localization: variants`, no released variant in the locale or along the site's fallback chain | problem details, [`path_not_found`](../reference/errors.md#path_not_found) |
| 410 | the variant or the placement was withdrawn, as [`variant.delete`](../addons/entry-commands.md#deleting-a-translation) withdraws a translation's placements, or the entry tombstoned or purged | problem details, [`path_gone`](../reference/errors.md#path_gone) |
| 421 | no site is served at the host | problem details, [`host_not_configured`](../reference/errors.md#host_not_configured) |

`data` is the record DTO of the entry's type, written by its generated codec through [`RecordCodecs`](../addons/contracts/record-codecs.md) at the public classification access, so no field above public is ever in the body (invariant 10). `canonical_url` is built from the origin of the canonical placement's site, never from the request.

## Languages and fallback

An entry of a type with `localization: variants` has a variant per language (PRD 10.1), and the path in a locale shows that locale's released variant, with the shared fields in it. When that variant has no release, the page falls back along the site's fallback chain, [`cbox-cms.sites.<handle>.fallback`](configuration.md#sites) (PRD 10.3): a request in a locale of the chain tries the locales after it in order and shows the first whose variant is released, whole, so a page never mixes languages by default. A field whose blueprint marks it `fallback: field` falls back on its own: when the variant shown leaves it empty (absent, null, an empty text or an empty list), it takes the value of the first later locale of the chain that has one. The rows of every locale tried are read in one statement, so the fallback costs no query more however long the chain is. With no released variant in any locale tried, the path answers 404.

`meta.locale` is the locale requested, `meta.resolved_locale` the locale of the variant shown, `meta.fallback` whether they differ, and `meta.fallback_fields` the fields that fell back on their own, by their address and sorted. For a type without localization they are the locale requested, `false` and `[]`. The explanation's `fallback` step names the locales tried; see [Delivery JSON](../addons/delivery-json.md#the-path-explanation). A release of a variant in any language purges the entry's `e-{entry}` key, so an answer that fell back is rebuilt once the locale requested has its own.

A site shows the placements of another node through a mount as they are at the source. When the site also has a placement of its own of the same entry, one that shows now below a node with a route of the site, that placement suppresses the mounted one: the mount URL answers 301 to it, and the source keeps resolving on its own site (PRD 5.8). A redirect is resolved in one hop (PRD 5.9): the target is a page that shows now, never another redirect, so an entry that cannot be shown, or an own placement that is hidden or outside its window, gives no redirect and the mount shows the source as before. The outcome is the same for every cause of a redirect. A slug a placement had before it was renamed with `placement.set_slug` answers 301 to the placement's current URL, and a path below a route a node had before `node.set_route` version 2 moved it answers 301 to the same rest of the path below the current route (PRD 5.9); the live lookup is asked first, then the slug history, then the route history, and the target is always the current URL, also after several renames, so the redirect is one hop. A renamed placement that is not visible answers at its old URL as at its current one, 410 for a withdrawn one. An entry that was merged adds its cause later.

## Content keys and cache headers

- `Surrogate-Key` holds the answer's content keys, `e-{entry}` and `n-{node}` (PRD 9.4): the entry's and the node it is placed under, for a 404 below a route the node the slug was looked up under, and through a mount the mount node. 301, 404 and 410 carry them too, so a publication, a new placement or a placement taken down purges them (PRD 8.10 point 10). The edge strips the header before the answer leaves it (point 4).
- `Cache-Control` is `public, max-age=0, s-maxage=<seconds>` for an answer the edge may keep. The seconds are the lower of `cbox-cms.delivery.max_age_seconds` and the time until a placement's window next changes the answer, its end for a visible placement and its start for one that has not opened (invariant 17), the same cap for 200, 404 and 410, and for a 301 the end of the target placement's window. A 200 whose window never ends also gets `stale-while-revalidate` and `stale-if-error`; an answer before a removal, a 301 and every problem get neither (PRD 8.12 points 3 and 4).
- `private, no-store` is sent for 421, for a malformed request, for a build under a purge fence, and for the explanation.
- `Cbox-Cache` is `hit` for an answer served from a fragment and `miss` for one built at the origin. No `Vary` is sent.

## Fragments

An answer the edge may keep is stored in the [`FragmentStore`](../addons/contracts/fragment-store.md) under a key of the host, the locale and the path, as a document of [`delivery-fragment.v1.json`](../addons/delivery-json.md), with its content keys, the position of the read it was built from and its lifetime; a redirect's fragment keeps its `Location`. The next request for the same key is served from the fragment, without the query pipeline and without a query, for what is left of that lifetime. When a dependency was purged at or above the read's position, the store refuses the fragment, and the answer is sent with `no-store` (PRD 8.12 point 1). The invalidation subscriber purges the fragments of an entry when it changes; see [subscribers](../addons/subscribers.md#the-kernels-invalidation-subscriber).

## The explanation

With `debug=1` the read runs as the principal of the request's Bearer credential, and the answer is `{"data":...,"explanation":{...},"meta":...,"problem":...,"status":...}`, a document of [`delivery-explanation.v1.json`](../addons/delivery-json.md): the record, the problem, or neither for a redirect, whose `status` is 301 and whose URL the explanation's `redirect` step names (the explanation itself sends no `Location`), and every step of the resolution, the site, the route, the node, a mount, the placement, the visibility decision with its valid_until, the fallback along the site's chain, the canonical placement and the redirect. Only an actor whose classification access is at least internal gets it; anyone else is answered with [`unauthorized`](../reference/errors.md#unauthorized). The explanation is never stored and always sent with `private, no-store`.
