---
title: The panel module
weight: 22
description: How an application mounts the control panel, the strict Content-Security-Policy on every panel page, the host runtime that renders the panel points, how the panel and the addons' bundles are built and their files served, the dev server for an addon's UI, and the page for an address the panel does not have.
---

# The panel module

The panel module, `Cbox\Cms\Panel` in `packages/panel`, is the PHP side of the control panel. Its React code is the npm workspace `js/panel`, on the component kit `js/ui-kit`. The module is a surface, as the http, cli and mcp modules are: it may use the core, the http module and the identity module, never cli, mcp, the testkit or the generators, and no other module uses it. Its controllers hold no logic of their own; the architecture tests hold both.

## Mounting the panel

An application mounts the panel in its web routes, inside the web middleware group, which gives the panel its session and CSRF protection: `PanelRoutes::register(app(Registrar::class))` mounts it at `/cms`, and a second argument names another prefix, such as `PanelRoutes::register(app(Registrar::class), 'admin')`. The workbench mounts it at `/cms`.

`PanelRoutes::register()` adds, below the prefix:

- `GET build/{path}`, named `cbox-cms.panel.asset`: a file of the panel's build;
- `GET theme/{version}.css`, named `cbox-cms.panel.theme`: the stylesheet of the theme `cms:build` composed from the themes the installation selects, and `GET brand/{name}`, named `cbox-cms.panel.brand`: a logo or the favicon of the installation's brand (see [Branding and theming the panel](panel-branding.md));
- `GET addons/{addon}/{hash}/{path}`, named `cbox-cms.panel.addon-asset`: a file of an addon's panel bundle, and `POST csp-report`, named `cbox-cms.panel.csp-report`: where a browser reports a violation of the policy below (see [Addon files and the dev server](#addon-files-and-the-dev-server));
- the panel's pages, each behind the Content-Security-Policy below and Inertia's middleware with the panel's root view:
  - `GET login`, named `cbox-cms.panel.login`, the login page, and `POST login`, named `cbox-cms.panel.login.submit`, a login from its form;
  - `GET forgot-password` and `POST forgot-password`, named `cbox-cms.panel.forgot-password` and `cbox-cms.panel.forgot-password.submit`, the page that asks for a password reset link and its form, and `GET reset-password/{token}` and `POST reset-password`, named `cbox-cms.panel.reset-password` and `cbox-cms.panel.reset-password.submit`, the page a reset link opens and its form;
  - behind the panel's session middleware, which sends a request without a session that verifies to the login page, and `SharePalette`, which gives every page the prop `palette`, the read of `action.list` the [command palette](../addons/command-palette.md) is built from: `GET` the prefix itself, named `cbox-cms.panel.home`, the start page; `POST logout`, named `cbox-cms.panel.logout`; `POST commands/{command}/v{version}`, named `cbox-cms.panel.command`, the Inertia command profile, which runs a command as the person who logged in; `GET account/me`, named `cbox-cms.panel.account-me`, the who-am-I page, which reads `actor.me` as the person ([panel pages](../addons/panel-pages.md)); and `GET x/{namespace}/{path}`, named `cbox-cms.panel.addon-page`, a page of an addon, its `PageContribution` at the path, with its data query's result as its props ([panel shell points](../addons/panel-shell.md)), or the page for a path the panel does not have when no addon has such a page or the person may not open it; the start page, as every page behind the login, is sent with `Cache-Control: no-store, private`, so no browser cache, back/forward cache or shared proxy keeps its props;
- last, any other path, named `cbox-cms.panel.not-found`: the page that says the panel has no page at the address, with 404 and a link back to the start of the panel.

## Logging in

The login page takes the email and password of a local account. A login that succeeds goes to the start page with a new session; one that is refused comes back to the login page with one message, whatever the reason, and logins are rate limited per email and per IP address. Every state-changing panel request is checked for the CSRF token of Laravel's session. Crossing the login boundary is always a full page load: after a login, a logout or a session that no longer verifies, a browser follows a 303, and an Inertia visit gets 409 with the address in `X-Inertia-Location`, on which Inertia loads the address as a new document, because a page behind the login carries the addons' import map and code and a credential page carries none, and a document's import map cannot change once it is loaded.

The login page links to the page that asks for a password reset link, which answers every email the same. The link in the mail opens the reset page, which takes a new password, ends the person's other sessions and signs them in. The link points at `cbox-cms.identity.password_reset.url`, which is `app.url` with `/cms/reset-password` by default, so an application that mounts the panel at another prefix sets it. [Local accounts](../security/local-accounts.md#resetting-a-password) describes the whole reset. [Sessions](../security/sessions.md#the-panel-and-laravels-session) describes the session cookie, how it relates to Laravel's session, and the rate limit.

## The Content-Security-Policy

Every panel page has a strict policy (GUARDRAILS 6). The middleware `SendContentSecurityPolicy` makes a nonce of 16 random bytes for each response, for the page's styles, and the root view `cms-panel::app` puts it on the build's stylesheets and on a `<meta property="csp-nonce">` element, where Inertia and Vite find it for the style elements they add later. Scripts are allowed by origin and by hash instead: the root view records the text of the page's one inline script, its import map, and the policy names its SHA-256. The policy is:

| Directive | Sources | What it means |
|---|---|---|
| `default-src` | `'self'` | Everything not named below comes from the panel's own origin. |
| `script-src` | `'self'`, the hash of the import map | Only the panel's own scripts and the page's import map run. No other inline script, no event handler attribute, no `eval`, and no `import()` from another origin. |
| `style-src` | `'self'`, the nonce | Stylesheets from the panel's origin, and style elements that carry the nonce. |
| `img-src` | `'self'`, `data:` | Images from the panel's origin, and data URLs. |
| `font-src`, `connect-src` | `'self'` | Fonts and requests to the panel's origin only. |
| `frame-src`, `object-src`, `base-uri` | `'none'` | No frames, no plugins, and no `<base>` element. |
| `form-action` | `'self'` | Forms post to the panel's origin only. |
| `frame-ancestors` | `'none'` | No other site may frame the panel. |
| `report-uri`, `report-to` | the report route | A browser reports what it blocked to `POST <prefix>/csp-report`; the response names the Reporting API's endpoint in `Reporting-Endpoints`. |

There is no `'unsafe-inline'`, no `'unsafe-eval'`, no nonce for scripts and no `'strict-dynamic'`.

The policy of the first panel pages used a nonce with `'strict-dynamic'` for scripts. Under it a script the nonce allows may `import()` a module from any origin, because the browser hands the importing script's nonce on to what it imports. `tests/Browser/Panel/PanelCspAddonModulesTest.php` shows it in Chromium, Firefox and WebKit, shows that Trusted Types do not close the gap and that `'self'` with the nonce does not either, and shows that only a `script-src` without a nonce, `'self'` with the hashes of the page's inline scripts, keeps `import()` on the panel's origin while the panel and an addon still run (decision D6). That is the policy now.

A violation the browser reports is counted on the telemetry counter `cms.panel.csp_violations` with the directive and the addon whose files were blocked or loading (`cms.panel.csp.directive`, `cms.panel.addon`, `none` for the panel's own), and nothing else of the report is kept. The report route takes the Reporting API's reports and a `report-uri` report alike, outside the CSRF check, and answers 204 whatever it holds.

## Shared modules

An addon's code runs on the panel's own React, never a copy of its own, so its hooks and the panel's work together. The panel's build therefore has an ES module entry for each module the panel shares, and the page's import map hands an addon those entries:

| Module | What an addon gets |
|---|---|
| `react` | the panel's React |
| `react/jsx-runtime` | its JSX runtime |
| `react-dom` | the panel's React DOM |
| `react-dom/client` | its `createRoot` and `hydrateRoot` |
| `@cboxdk/cms-panel/ui`, `/extend`, `/experimental` | the panel's own copy of the SDK: the kit's components, the panel API and the experimental API |

React 19 ships as CommonJS, so each React entry names every export of React's production build itself and re-exports it from the module the panel's own code imports; the SDK's subpaths are ES modules and are re-exported as they are. The panel and each entry share one chunk, so there is one React and one SDK. `js/panel/shared-modules.json` lists the modules with the name of each entry, and the panel module's `ViteManifest` finds the entries in Vite's manifest by those names; a build without one of them is refused.

Some modules of the panel are not for addons: its router and page state, `@inertiajs/react` and `@inertiajs/core`, the headless primitives, `react-aria-components`, and the component kit's own package, `@cboxdk/cms-ui-kit`, whose components an addon gets from the SDK. Below each addon's prefix, the import map maps them to an entry that throws before the addon runs, so the addon fails at once with an error named `PanelImportRefused` whose message names the module and points here: `Cbox CMS panel: an addon may not import @inertiajs/react. The panel's router, page state and UI primitives are not addon API; an addon shares only the modules of the panel's import map (docs/developers/panel.md#shared-modules).`

Outside an addon's prefix nothing maps them, so a bare import of them fails to resolve. An addon reaches the panel only through the shared modules.

The import map is the page's only one, as Firefox takes one map per document. The root view writes it before any stylesheet, preload or module, and the policy names its hash: `imports` for the shared modules and, on a page that loads addons, each addon's entry under `cms-addons/<namespace>`, `scopes` for each addon's prefix and `integrity` with the SHA-384 of every script of the build and of every addon's script, which the browser checks before it runs a module, so a file whose bytes changed after the build does not run. `tests/Browser/Panel/SharedExternalsTest.php` and `PanelCspAddonModulesTest.php` load a test addon's module lazily through the map on a panel page, in Chromium, Firefox and WebKit, and check that its hooks run on the panel's React, that `@inertiajs/react` is refused with the error above and that the browser reports no violation of the policy.

## The host runtime

Every page of the panel renders inside the host runtime, `js/panel/src/host`, which renders the page's panel points from its `cms.contributions` ([panel contributions](../addons/panel-contributions.md#what-a-page-sends-a-viewer)). A page renders a point with `<PointHost point="<name>@<version>">` and asks a point for what it does not render with `usePointHost('<name>@<version>')`, each with the point's id as a string literal, so `tests/Feature/Panel/RenderedPanelPointsTest.php` can hold every point a page names to the declared ones and every declared point to a page. What the host does depends on the point's kind:

- A slot renders each contribution's component with the point's props, frozen, and its data: loading until the deferred prop of its addon arrives, then ready with the value, or failed when the query gave none. In the `sections` and `aside` regions a component renders its own markup. In a structured region a contribution gives descriptors the host renders with the kit, so tables, tabs and toolbars stay accessible: a toolbar item (a badge or a button), a column (header, width and cell, which a page asks `usePointHost()` for) or a tab (label and panel, after the page's own tabs). A slot shows at most its point's maximum, and an exclusive one one contribution; past the maximum, toolbar buttons overflow into a menu.
- An action point renders a kit button per action, its text in its addon's catalogue, overflowing into a menu past the point's maximum. A press runs the action's command through the contribution's own host, which holds the addon to its `issues` and sends the provenance `addon:<namespace>:<contribution>`, with the document prefilled from the point's props by JSON pointer, asking first as the action's manifest says: at once, after the viewer confirms in the kit's dialog, or after a dry run whose summary the viewer reviews in the kit's `DryRunReport` before the command runs for real. The receipt, and the problem details of a rejection, are shown where the actions are; an action whose confirm is `form` is handed to the page, which opens the command's form. An action runs no code of its addon.
- A page point renders the addon's page component of the page shown, registered in the bundle under the contribution's id, with its data from the deferred prop of its addon, in its boundary and scope; nothing renders for a page the server did not list.
- A nav point's entries come from `usePointHost('shell.nav@1').nav`: each with its text in its addon's catalogue and the address of the page it opens, in render order, which the shell renders as the kit's `SideNav` and `paletteEntries()` gives the command palette as its pages.
- A decorator point renders the page's default once, whatever the decorators answer or throw. A decorator is a function of the point's props and never receives the default, so it cannot remove it; the host renders what the decorators add before and after the default, the first decorator outermost, their badges, and passes the default the props they tighten, combined most restrictively: a disabled reason only disables, a description is only appended, and a tone only moves towards warning or danger. A decorator tightens only what its manifest declares.
- A replacement point renders the contribution that won the page's target, such as a field type, with the page's props, and the page's default when none won it, while it loads, and with a notice that names its addon when it throws.
- A form check runs on a frozen copy of the command document within 16 ms; a check that throws or runs over is skipped for the rest of the session. A check that answers with a promise (experimental) gets 300 ms and is cancelled by the next edit. The issues are sorted by path, then addon, are in their addon's namespace, weigh at most what the check declares, so only a check that mirrors a hook of its addon blocks the submit, and an issue to acknowledge holds the submit until it is ticked.
- A flow's steps run in order (`FlowHost`). A step patches only the paths its manifest declares, below `ext.<namespace>` of its addon or anywhere in a command of its own, and the host refuses any other patch. The first cancel, a throw or a timeout stops the flow in the step's addon's name. Before the submit the core's own confirmation always runs last: the flow reaches it after every step and ends only through it.
- Observers are called in order with a frozen copy of the event, and one that throws does not stop the others.

Each contribution renders inside its own error boundary and scope wrapper, an element without a box of its own that names its addon, point and contribution, and inside which `usePanelHost()` gives the contribution's own host: the texts of its addon's catalogue, formatting in the panel's locale, notices in the kit's toast region, navigation to the pages `cms.contributions` names, dialogs in the kit's confirmation dialog, and the commands its addon may issue, run through the Inertia profile as the viewer with an idempotency key per call and the provenance `addon:<namespace>:<contribution>` as a source of the envelope, answered with the receipt, the summary of a dry run and the problem details of a rejection. One contribution that throws, or whose module fails to load, leaves the rest of the page rendered; the viewer sees a notice that names the addon, and a viewer with internal access sees what was thrown.

Before it renders any contribution of an addon, the host holds the addon's code to what `cms:build` compiled: it takes the SHA-256 of the ids the addon's `definePanelAddon()` registered, sorted and joined by line feeds, and compares it with the registration `cms.contributions` names. A module that exports no registration, one made with an SDK of another major version of the panel API or a newer minor, or one that registers other ids renders none of the addon's contributions. The core's own contributions, in the namespace `cms`, are registered by the panel's build and held to the same check. An addon's entry module is imported through the page's import map by the bare specifier `cms-addons/<namespace>`; an addon whose module cannot be loaded shows one notice per point.

Every failure is reported once per session as a code with the addon, the point and the contribution, never a message a contribution wrote, and dispatched on the window as the event `cms:panel-report`: `panel_addon_mismatch`, `panel_addon_unavailable`, `panel_contribution_failed`, `panel_replacement_failed`, `panel_decorator_failed`, `panel_decorator_tightening_refused`, `panel_check_failed`, `panel_check_over_budget`, `panel_check_issue_refused`, `panel_step_failed`, `panel_step_timed_out`, `panel_step_patch_refused`, `panel_observer_failed`, `panel_point_kind_mismatch`, `panel_command_refused`, `panel_navigation_refused`, `panel_action_failed` and `panel_action_unhandled`. `npm run test:js -- host` runs the host's tests, in `js/panel/tests/host`.

The section "Panel points" of the Storybook gate 7 tests is generated from `panel.php`, so no point exists without a story: `vendor/bin/testbench cms:panel:stories` writes `js/panel/stories/generated`, an overview of every point and a story per point that shows its facts, the order its contributions render in, its props schema and the point as a page renders it, with its sample props and the contributions compiled for it. Run `cms:build` first, and run both after declaring a point; `packages/generators/tests/Cli/PanelStoriesCommandTest.php` fails when the committed stories are not what the command writes. It runs only in the repository of `cboxdk/cms`, and exits 78 when the registry cannot be read.

## The build and its files

PRD 13.4 has the panel ship prebuilt, so an application's deploy needs no Node for it; the prebuilt distribution that a release carries comes with the second part of the panel skeleton. In this repository `composer panel:build` builds `js/panel` with Vite into `packages/panel/dist`, which git ignores. It runs in the php-baseimages dev image with the checkout's own `node_modules` volume, never the host's, which holds macOS binaries, and in place when it already runs in the image, as in CI. Gate 8 runs it before the browser tests.

The module reads the build from the manifest Vite writes, `packages/panel/dist/.vite/manifest.json`, when a panel page or file is first asked for, so a process without the build boots and runs everything but the panel. A panel page without the build fails with an error that names the manifest and says to run `composer panel:build`. Each build has a version, the SHA-256 of its manifest, which Inertia compares on every visit, so a browser that holds a page of an older build loads the new one in full.

`GET <prefix>/build/{path}` serves only the files the manifest names, with their content type, `Cache-Control: public, max-age=31536000, immutable`, because their names carry a hash of their content, and `X-Content-Type-Options: nosniff`. Any other path, the manifest itself included, is 404, so no request reaches another file.

## Addon files and the dev server

An addon's panel UI is a prebuilt bundle its manifest names, built with the SDK's Vite plugin ([panel contributions](../addons/panel-contributions.md#the-bundle)) and signed by its publisher, whose key the installation trusts in `cbox-cms.addons.publishers` ([signing the bundle](../addons/panel-contributions.md#signing-the-bundle)), and `cms:build` keeps the bundle's entry and every file with its SHA-384 in the compiled registry. The panel serves the files below `GET <prefix>/addons/{addon}/{hash}/{path}`, where the hash is the SHA-256 of the compiled files' paths and hashes (`BundleHash`): only a file the registry lists for the addon, at that hash, is served, with its content type, `Cache-Control: public, max-age=31536000, immutable`, `Cross-Origin-Resource-Policy: same-origin` and `X-Content-Type-Options: nosniff`; any other address is 404. The bytes are read from the directory the addon's provider names in its manifest and hashed before they are sent, and a file whose SHA-384 is not the compiled one, or that is gone, is refused with the problem details of `panel_asset_hash_mismatch`, because the bundle changed after `cms:build`; `cms:doctor`'s `panel.addons` reports the same change, and `panel.dev_server` the variable below.

Only a page whose route loads addons gets them: the start page and the pages behind the login carry `cbox-cms.panel.addons` as true in their route action, and the root view then writes every installed bundle into the import map, the entry under `cms-addons/<namespace>`, which the host imports an addon by, a scope below the bundle's address with the refused modules, and the integrity of each script, and links every bundle's stylesheets with the nonce. The login, password reset and logout routes, the page for an address the panel does not have and every route that is no page carry false, so no addon's code runs near a password or a reset link; `packages/panel/tests/Feature/CredentialRoutesWithoutAddonsTest.php` holds every credential route to it, and `tests/Browser/Panel/AddonAssetsTest.php` loads an addon through the map on the start page and shows a changed file refused.

While an addon's UI is developed, a local application can load it from the addon's Vite dev server instead of its bundle: `CBOX_CMS_PANEL_DEV_ADDONS=<namespace>=<origin>`, pairs joined by commas, each the loopback origin of a server over http, such as `approvals=http://localhost:5174`. The import map then maps the addon's entry to `<origin>/@cms-panel-addon/entry`, which the plugin serves, and each shared module as the server's import analysis writes it, `<origin>/@id/<specifier>`, to the panel's own copy; the page loads the server's client, `<origin>/@vite/client`, for hot module replacement; and the policy lets the page load scripts from the origin and connect to it and its websocket, on the pages that load addons alone. The variable is read from the process's environment, never from the configuration. It works only in the local environment: the panel's provider refuses to boot a process that serves HTTP or runs queued jobs with it elsewhere, with `panel_dev_server_forbidden`, or with a value it cannot read, with `panel_dev_addons_invalid`, and `cms:doctor`'s `panel.dev_server` reports both as a violation.

## Page props

<!-- extension-point: packages/panel/resources/schemas/pages/account-me.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/access-roles.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/access-grants.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/access-grant-pickers.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/palette.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/addon-page.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/command-form.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/pages/brand.v1.json -->
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
| `Addon`, an addon's page below `/x/<namespace>/` | `addon-page.v1.json` | `AddonPage` | `AddonPageV1` |
| `Account/Me`, the who-am-I page | `account-me.v1.json` | `AccountMePage` | `AccountMePageV1` |
| `Command`, the generic command form at `/commands/<name>/v<version>` | `command-form.v1.json` | `CommandFormPage` | `CommandFormPageV1` |
| `Access/Roles`, the roles page | `access-roles.v1.json` | `AccessRolesPage` | `AccessRolesPageV1` |
| `Access/Grants`, the grants page | `access-grants.v1.json` | `AccessGrantsPage` | `AccessGrantsPageV1` |
| `Access/Grants`, the optional prop `pickers` | `access-grant-pickers.v1.json` | `GrantPickers` | `GrantPickersV1` |
| every page behind the login, the prop `palette` | `palette.v1.json` | `PaletteProp` | `PalettePropV1` |
| every page, the prop `brand` | `brand.v1.json` | `PanelBrand` | `PanelBrandV1` |

`composer generate:protocol` writes, from the schemas, a codec per page into `packages/panel/src/Boundary/Generated` and, into `js/panel/src/generated`, a module per page in `pages/` with the props' TypeScript types and a validator, next to the validators' runtime module, and a module per kernel contract the panel reads in `protocol/`: the receipt, the problem details and the dry run summary the host reads commands' answers with, and the result of `actor.me` the who-am-I page reads from its props, the result of `action.list` the command palette reads from the shared prop `palette`, and the results of `role.list`, `grant.list`, `actor.list` and `node.list` the roles and grants pages and the pickers of the grants page read (`PanelPageSchemas::PROTOCOL_CODECS`). A page that reads carries the read's result and rejection as documents of the kernel's contracts, `result` and `rejection`, one of the two and never both, and validates the result with the contract's generated validator before it shows it. The command form's props carry the command's JSON Schema as a document, `schema`, as the command's codec carries it, and the page renders the form from it ([command form](../addons/command-form.md)). A read a page needs only when a form opens, such as the pickers of the grants page, is an Inertia optional prop of its own schema, which the server resolves only on a partial reload that asks for it, and which carries neither result nor rejection for a read the pipeline could not make. `PanelPages` renders each page with the props its codec writes, and each page in `js/panel` takes the generated type as its props. Gate 6 fails when the committed files differ from what the schemas give.

Beside its own props, every page behind the login, the start page included, sends `cms.contributions`, the contributions active for the person who signed in, and a deferred prop `ext.<namespace>` for each addon whose contributions read data ([panel contributions](../addons/panel-contributions.md#what-a-page-sends-a-viewer)).

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
    'the brand every page shares, without branding' => ['brand.v1.json', '{"login":null,"logo":null,"name":"Cbox CMS"}'],
    'the brand every page shares, with a logo' => ['brand.v1.json', '{"login":{"alt":"Skovbo","dark":"/cms/brand/logo-dark-0123456789abcdef.svg","light":"/cms/brand/logo-light-0123456789abcdef.svg"},"logo":{"alt":"Skovbo","dark":"/cms/brand/logo-dark-0123456789abcdef.svg","light":"/cms/brand/logo-light-0123456789abcdef.svg"},"name":"Skovbo Content"}'],
]);

it('refuses a logo without its alternative text', function (): void {
    expect(pagePropsErrors('brand.v1.json', '{"login":null,"logo":{"alt":"","dark":"/cms/brand/logo-dark-0123456789abcdef.svg","light":"/cms/brand/logo-light-0123456789abcdef.svg"},"name":"Skovbo Content"}'))
        ->not->toBe([]);
});

it('refuses a refusal code the page does not know', function (): void {
    expect(pagePropsErrors('login.v1.json', '{"action":"/cms/login","forgot":"/cms/forgot-password","reason":null,"refusals":{"email":null,"form":"password_too_short","password":null}}'))
        ->not->toBe([]);
});
```
