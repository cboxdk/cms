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
- the panel's pages, each behind the Content-Security-Policy below and Inertia's middleware with the panel's root view:
  - `GET login`, named `cbox-cms.panel.login`, the login page, and `POST login`, named `cbox-cms.panel.login.submit`, a login from its form;
  - `GET forgot-password` and `POST forgot-password`, named `cbox-cms.panel.forgot-password` and `cbox-cms.panel.forgot-password.submit`, the page that asks for a password reset link and its form, and `GET reset-password/{token}` and `POST reset-password`, named `cbox-cms.panel.reset-password` and `cbox-cms.panel.reset-password.submit`, the page a reset link opens and its form;
  - behind the panel's session middleware, which sends a request without a session that verifies to the login page: `GET` the prefix itself, named `cbox-cms.panel.home`, the start page; `POST logout`, named `cbox-cms.panel.logout`; and `POST commands/{command}/v{version}`, named `cbox-cms.panel.command`, the Inertia command profile, which runs a command as the person who logged in; the start page, as every page behind the login, is sent with `Cache-Control: no-store, private`, so no browser cache, back/forward cache or shared proxy keeps its props;
- last, any other path, named `cbox-cms.panel.not-found`: the page that says the panel has no page at the address, with 404 and a link back to the start of the panel.

## Logging in

The login page takes the email and password of a local account. A login that succeeds goes to the start page with a new session; one that is refused comes back to the login page with one message, whatever the reason, and logins are rate limited per email and per IP address. Every state-changing panel request is checked for the CSRF token of Laravel's session.

The login page links to the page that asks for a password reset link, which answers every email the same. The link in the mail opens the reset page, which takes a new password, ends the person's other sessions and signs them in. The link points at `cbox-cms.identity.password_reset.url`, which is `app.url` with `/cms/reset-password` by default, so an application that mounts the panel at another prefix sets it. [Local accounts](../security/local-accounts.md#resetting-a-password) describes the whole reset. [Sessions](../security/sessions.md#the-panel-and-laravels-session) describes the session cookie, how it relates to Laravel's session, and the rate limit.

## The Content-Security-Policy

Every panel page has a strict policy with a nonce of its own (GUARDRAILS 6). The middleware `SendContentSecurityPolicy` makes 16 random bytes for each response, and the root view `cms-panel::app` puts that nonce on the page's import map, the build's script, its stylesheets and module preloads, and on a `<meta property="csp-nonce">` element, where Inertia and Vite find it for the style and preload elements they add later. The policy is:

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

Under `'strict-dynamic'` a script the nonce allows may `import()` a module from any origin, because the browser hands the importing script's nonce on to what it imports. The browser tests show it in Chromium, Firefox and WebKit, and show that Trusted Types do not close the gap and that `'self'` with the nonce does not either; only a `script-src` without a nonce, `'self'` with the hashes of the page's inline scripts, keeps `import()` on the panel's origin. How the panel closes the gap comes with the serving of addon files.

## Shared modules

An addon's code runs on the panel's own React, never a copy of its own, so its hooks and the panel's work together. The panel's build therefore has an ES module entry for each module the panel shares, and the page's import map hands an addon those entries:

| Module | What an addon gets |
|---|---|
| `react` | the panel's React |
| `react/jsx-runtime` | its JSX runtime |
| `react-dom` | the panel's React DOM |
| `react-dom/client` | its `createRoot` and `hydrateRoot` |

React 19 ships as CommonJS, so each entry names every export of React's production build itself and re-exports it from the module the panel's own code imports; the panel and the entry share one chunk, so there is one React. `js/panel/shared-modules.json` lists the modules with the name of each entry, and the panel module's `ViteManifest` finds the entries in Vite's manifest by those names; a build without one of them is refused.

Some modules of the panel are not for addons: its router and page state, `@inertiajs/react` and `@inertiajs/core`. Below each addon's prefix, the import map maps them to an entry that throws before the addon runs, so the addon fails at once with an error named `PanelImportRefused` whose message names the module and points here: `Cbox CMS panel: an addon may not import @inertiajs/react. The panel's router, page state and UI primitives are not addon API; an addon shares only the modules of the panel's import map (docs/developers/panel.md#shared-modules).`

Outside an addon's prefix nothing maps them, so a bare import of them fails to resolve. An addon reaches the panel only through the shared modules.

The import map is the page's only one, as Firefox takes one map per document. The root view writes it, with the nonce, before any stylesheet, preload or module: `imports` for the shared modules, `scopes` for each addon's prefix and `integrity` with the SHA-384 of every script of the build, which the browser checks before it runs a module, so a file whose bytes changed after the build does not run. `tests/Browser/Panel/SharedExternalsTest.php` and `PanelCspAddonModulesTest.php` load a test addon's module lazily through the map on a panel page, in Chromium, Firefox and WebKit, and check that its hooks run on the panel's React, that `@inertiajs/react` is refused with the error above and that the browser reports no violation of the policy.

## The build and its files

PRD 13.4 has the panel ship prebuilt, so an application's deploy needs no Node for it; the prebuilt distribution that a release carries comes with the second part of the panel skeleton. In this repository `composer panel:build` builds `js/panel` with Vite into `packages/panel/dist`, which git ignores. It runs in the php-baseimages dev image with the checkout's own `node_modules` volume, never the host's, which holds macOS binaries, and in place when it already runs in the image, as in CI. Gate 8 runs it before the browser tests.

The module reads the build from the manifest Vite writes, `packages/panel/dist/.vite/manifest.json`, when a panel page or file is first asked for, so a process without the build boots and runs everything but the panel. A panel page without the build fails with an error that names the manifest and says to run `composer panel:build`. Each build has a version, the SHA-256 of its manifest, which Inertia compares on every visit, so a browser that holds a page of an older build loads the new one in full.

`GET <prefix>/build/{path}` serves only the files the manifest names, with their content type, `Cache-Control: public, max-age=31536000, immutable`, because their names carry a hash of their content, and `X-Content-Type-Options: nosniff`. Any other path, the manifest itself included, is 404, so no request reaches another file.

## Page props

<!-- extension-point: packages/panel/resources/schemas/pages/forgot-password.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/home.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/login.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/not-found.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/reset-password.v1.json -->

The props of each panel page are written from PHP and typed from PHP, never by hand on either side (GUARDRAILS 2.2, 2.4). Each page has a JSON Schema in `packages/panel/resources/schemas/pages`, one file per page and contract version, bound to a DTO in `Cbox\Cms\Panel\Domain\Dto`:

| Page | Schema | DTO | TypeScript |
|---|---|---|---|
| `Auth/Login` | `login.v1.json` | `LoginPage` | `LoginPageV1` |
| `Auth/ForgotPassword` | `forgot-password.v1.json` | `ForgotPasswordPage` | `ForgotPasswordPageV1` |
| `Auth/ResetPassword` | `reset-password.v1.json` | `ResetPasswordPage` | `ResetPasswordPageV1` |
| `Home` | `home.v1.json` | `HomePage` | `HomePageV1` |
| `Errors/NotFound` | `not-found.v1.json` | `NotFoundPage` | `NotFoundPageV1` |

`composer generate:protocol` writes, from the schemas, a codec per page into `packages/panel/src/Boundary/Generated` and, into `js/panel/src/generated`, a module per page in `pages/` with the props' TypeScript types and a validator, next to the validators' runtime module. `PanelPages` renders each page with the props its codec writes, and each page in `js/panel` takes the generated type as its props. Gate 6 fails when the committed files differ from what the schemas give.

The reason the panel sent a browser to the login page is the enum `SignInReason`, and the refusal of a form just posted is a typed member of the props, `refusals`, with the refusal under the field it is about, or under `form` for the form as a whole. Each refusal is an enum of the panel's Domain whose values are the catalog codes the form is refused with (`LoginRefusal`, `ForgotPasswordRefusal`, `ResetFormRefusal` and `ResetPasswordRefusal`), so the TypeScript type holds exactly those codes and a page that does not give each one a text fails tsc. A new prop, reason or code therefore starts in the schema; `tests/Codecs/PanelPageValidatorsTest.php` runs the generated validators against what every page renders in each of its states.

This example checks props against the schemas. It is in the `Codecs` suite:

<!-- example: examples/Codecs/Panel/PagePropsTest.php -->
```php
<?php

declare(strict_types=1);

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;

// The props of each panel page are a document of the page's JSON Schema in
// packages/panel/resources/schemas/pages. A refusal is one of the catalog codes the schema lists
// for its field, and anything else, such as a code the page does not know, is not a valid document.

/**
 * The errors of a document against a page's schema, none when it is valid.
 *
 * @return array<array-key, mixed>
 */
function pagePropsErrors(string $schema, string $document): array
{
    $json = file_get_contents(dirname(__DIR__, 3).'/packages/panel/resources/schemas/pages/'.$schema)
        ?: throw new RuntimeException("Cannot read {$schema}.");
    $error = new CompliantValidator()->validate(json_decode($document), $json)->error();

    return $error instanceof ValidationError ? new ErrorFormatter()->format($error) : [];
}

it('accepts the props of a page', function (string $schema, string $document): void {
    expect(pagePropsErrors($schema, $document))->toBe([]);
})->with([
    'the login page after a wrong password' => ['login.v1.json', '{"action":"/cms/login","forgot":"/cms/forgot-password","reason":null,"refusals":{"email":null,"form":"login_rejected","password":null}}'],
    'the login page after a session expired' => ['login.v1.json', '{"action":"/cms/login","forgot":"/cms/forgot-password","reason":"expired","refusals":{"email":null,"form":null,"password":null}}'],
    'the page that asks for a link, just asked' => ['forgot-password.v1.json', '{"action":"/cms/forgot-password","login":"/cms/login","minutes":60,"refusals":{"email":null},"requested":true}'],
    'the reset page after a short password' => ['reset-password.v1.json', '{"action":"/cms/reset-password","forgot":"/cms/forgot-password","login":"/cms/login","refusals":{"form":null,"password":"password_too_short"},"token":null}'],
    'the start page' => ['home.v1.json', '{"logout":"/cms/logout"}'],
    'the page for a path the panel does not have' => ['not-found.v1.json', '{"home":"/cms"}'],
]);

it('refuses a refusal code the page does not know', function (): void {
    expect(pagePropsErrors('login.v1.json', '{"action":"/cms/login","forgot":"/cms/forgot-password","reason":null,"refusals":{"email":null,"form":"password_too_short","password":null}}'))
        ->not->toBe([]);
});
```
