---
title: The panel module
weight: 22
description: How an application mounts the control panel, the strict Content-Security-Policy with a nonce on every panel page, how the panel is built and its files served, and the page for an address the panel does not have.
---

# The panel module

The panel module, `Cbox\Cms\Panel` in `packages/panel`, is the PHP side of the control panel. Its React code is the npm workspace `js/panel`, on the component kit `js/ui-kit`. The module is a surface, as the http, cli and mcp modules are: it may use the core, the http module and the identity module, never cli, mcp, the testkit or the generators, and no other module uses it. Its controllers hold no logic of their own; the architecture tests hold both.

## Mounting the panel

An application mounts the panel in its web routes, inside the web middleware group, which gives the panel its session and CSRF protection: `PanelRoutes::register(app(Registrar::class))` mounts it at `/cms`, and a second argument names another prefix, such as `PanelRoutes::register(app(Registrar::class), 'admin')`. The workbench mounts it at `/cms`.

`PanelRoutes::register()` adds, below the prefix:

- `GET build/{path}`, named `cbox-cms.panel.asset`: a file of the panel's build;
- the panel's pages, each behind the Content-Security-Policy below and Inertia's middleware with the panel's root view;
- last, any other path, named `cbox-cms.panel.not-found`: the page that says the panel has no page at the address, with 404 and a link back to the start of the panel.

## The Content-Security-Policy

Every panel page has a strict policy with a nonce of its own (GUARDRAILS 6). The middleware `SendContentSecurityPolicy` makes 16 random bytes for each response, and the root view `cms-panel::app` puts that nonce on the build's script, its stylesheets and module preloads, and on a `<meta property="csp-nonce">` element, where Inertia and Vite find it for the style and preload elements they add later. The policy is:

| Directive | Sources | What it means |
|---|---|---|
| `default-src` | `'self'` | Everything not named below comes from the panel's own origin. |
| `script-src` | the nonce, `'strict-dynamic'` | Only a script the response names with its nonce runs, with what it imports. No inline script, event handler attribute or `eval`. |
| `style-src` | `'self'`, the nonce | Stylesheets from the panel's origin, and style elements that carry the nonce. |
| `img-src` | `'self'`, `data:` | Images from the panel's origin, and data URLs. |
| `font-src`, `connect-src` | `'self'` | Fonts and requests to the panel's origin only. |
| `object-src`, `base-uri` | `'none'` | No plugins, and no `<base>` element. |
| `form-action` | `'self'` | Forms post to the panel's origin only. |
| `frame-ancestors` | `'none'` | No other site may frame the panel. |

There is no `'unsafe-inline'` and no `'unsafe-eval'`.

## The build and its files

PRD 13.4 has the panel ship prebuilt, so an application's deploy needs no Node for it; the prebuilt distribution that a release carries comes with the second part of the panel skeleton. In this repository `composer panel:build` builds `js/panel` with Vite into `packages/panel/dist`, which git ignores. It runs in the php-baseimages dev image with the checkout's own `node_modules` volume, never the host's, which holds macOS binaries, and in place when it already runs in the image, as in CI. Gate 8 runs it before the browser tests.

The module reads the build from the manifest Vite writes, `packages/panel/dist/.vite/manifest.json`, when a panel page or file is first asked for, so a process without the build boots and runs everything but the panel. A panel page without the build fails with an error that names the manifest and says to run `composer panel:build`. Each build has a version, the SHA-256 of its manifest, which Inertia compares on every visit, so a browser that holds a page of an older build loads the new one in full.

`GET <prefix>/build/{path}` serves only the files the manifest names, with their content type, `Cache-Control: public, max-age=31536000, immutable`, because their names carry a hash of their content, and `X-Content-Type-Options: nosniff`. Any other path, the manifest itself included, is 404, so no request reaches another file.
