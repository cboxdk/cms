---
title: Panel UI
weight: 10
description: "How an addon extends the control panel: the panel points and the kinds of contribution, the SDK and the bundle, testing and stability, and the trust model the panel runs an addon's UI under."
---

# Panel UI

An addon changes the control panel only at the panel's declared points (PRD 13.4). A point is a place on a page with a typed contract: an id `<name>@<version>`, such as `account.me.sections@1`, a kind, a props class with a JSON Schema, and a stability. An addon lists every contribution it makes in the `panel` member of its manifest. `cms:build` checks each one against its point and writes the result to `panel.php`, and on each request the server decides which contributions the viewer gets. What the manifest lists is exactly what the addon may touch.

Every point, every contribution class and the SDK's panel API are experimental in block B1 (decision D4), so an addon lists each point it contributes to in `acceptsExperimental` and imports the experimental types from `@cboxdk/cms-panel/experimental`. [Stability and deprecation](stability.md) says what that promises.

## What an addon can do

| To | Kind | Points of block B1 |
|---|---|---|
| add a section to a page | [slot](kinds/slot.md) | [`account.me.sections@1`](points/account-me-sections.md), [`access.roles.sections@1`](points/access-roles-sections.md), [`access.grants.sections@1`](points/access-grants-sections.md), [`command.form.aside@1`](points/command-form-aside.md), [`command.form.dryrun@1`](points/command-form-dryrun.md) |
| add a page of its own | [page](kinds/page.md) | [`shell.page@1`](points/shell-page.md) |
| add a nav entry to its page | [nav](kinds/nav.md) | [`shell.nav@1`](points/shell-nav.md) |
| add a button that runs a command | [action](kinds/action.md) | [`shell.user-menu@1`](points/shell-user-menu.md) |
| warn about a command's draft, or block it with a mirrored hook | [form check](kinds/form-check.md) | [`command.form.checks@1`](points/command-form-checks.md) |
| add a step before the submit or after the receipt | [flow step](kinds/flow-step.md) | [`command.form.steps@1`](points/command-form-steps.md) |
| add to a default and tighten it | [decorator](kinds/decorator.md) | [`command.form.submit@1`](points/command-form-submit.md), [`command.form.receipt@1`](points/command-form-receipt.md) |
| replace the input of its own value class | [replacement](kinds/replacement.md) | [`command.form.field@1`](points/command-form-field.md) |
| be told of every command a page ran | [observer](kinds/observer.md) | [`panel.observe.command@1`](points/panel-observe-command.md) |
| show a notice on the login page | [data](kinds/data.md) | [`login.notice@1`](points/login-notice.md) |
| set token values | [theme](kinds/theme.md) | none; the installation selects themes |
| wrap a subtree | [provider](kinds/provider.md) | none in block B1 |

## Data first

An action, a nav entry, a login notice and a theme are data: the manifest holds them and the panel renders them with the component kit, so they need no code of the addon. Only the kinds that must run code, a slot, a page, a decorator, a replacement, a form check, a flow step, an observer and a provider, have a module in the addon's bundle, registered by the contribution's id with `definePanelAddon()` of the [SDK](sdk.md).

## What an addon can never do

An addon can add content, warnings, restrictions and steps. It cannot remove or loosen what the core or another addon shows or enforces:

- A decorator never receives the default it decorates, so it cannot drop it. It tightens only the props its manifest declares, and the host combines what several decorators tighten most restrictively.
- A form check only adds issues. Only a check that mirrors a `ValidateHook` or `AuthorizeHook` of its own addon on the same command may block the submit, and the host weighs every issue down to the severity the manifest declares.
- A flow step can cancel the flow, but the core's own confirmation always runs last and no step can skip it. A step patches only the paths its manifest declares, below `ext.<namespace>` or in a command of its own.
- A replacement replaces only a key its addon owns, at a point with `Ownership::Own`.
- A theme sets only semantic and component tokens, and `cms:build` refuses a composition of themes that draws a contrast pair below WCAG 2.2 AA.
- An addon sets the priority of its own contributions alone. Only the installation reorders or disables the others, the core's own included.

Each contribution renders inside its own error boundary, so one that throws shows a notice that names its addon and the rest of the page renders. The server stays the authority on every command and read: the panel runs them as the viewer, through the same pipelines as REST.

## How an addon's UI ships

An addon ships a prebuilt bundle inside its Composer package, so an installation needs no Node to deploy it. The bundle is built with the SDK's Vite plugin and signed by its publisher with an Ed25519 key. `cms:build` checks the bundle's files against their SHA-384 and the signature against the key the installation trusts for the addon. The panel serves only the compiled files, by hash, and the page's import map hands the addon the panel's own React and SDK. The host imports an addon's code only when a page renders one of its contributions. The login and password reset pages, and the page for an address the panel does not have, run no addon code at all. [Panel contributions](contributions.md) has the details.

## The trust model

An addon's UI runs in the panel's window with the viewer's session. The browser cannot isolate it from the page: an installed addon is trusted code. The panel limits what it hands a contribution, and the server decides what the viewer may do, but a hostile installed addon could read what the viewer sees and act as the viewer. [Panel addons](../../security/panel-addons.md) states what is enforced where, and what is not.

## The pages of this section

- [Panel SDK](sdk.md): `@cboxdk/cms-panel`, its subpaths, `definePanelAddon` and `usePanelHost`, and the types `cms:panel:types` writes.
- [Panel contributions](contributions.md): the `panel` member of the manifest, every check `cms:build` runs, the bundle and its signature, order and the kill switch, and what a page sends a viewer.
- [Kinds of contribution](kinds/_index.md): one page per kind, with what it receives, its rules and a running example.
- [Panel points](points/_index.md): how a point is declared, the panel registry, and one page per point of block B1.
- [Panel shell](shell.md), [panel pages](pages.md), [command form](command-form.md) and [command palette](command-palette.md): the pages the points sit on.
- [Testing and scaffolding addon UI](testing.md): the SDK's test helpers, `cms-panel-addon verify`, the testkit's contract and browser helpers, and the scaffolds.
- [Stability and deprecation](stability.md): the stability levels, the panel API version, point versions and downcasts, the compatibility lock and the API reports.

The recipes [add a panel action without code](../../recipes/panel-action.md), [add a section with data](../../recipes/panel-section.md), [add a form check mirrored by a hook](../../recipes/panel-check.md), [add a flow step](../../recipes/panel-step.md), [ship a theme](../../recipes/panel-theme.md) and [add a point to a core page](../../recipes/panel-point.md) walk through the common tasks.

The workbench's fixture addon, `workbench/addons/fixtureaddon`, contributes to every point of block B1 and ships a theme, and the examples on the point pages run its real contributions. Its manifest is [`FixtureAddonServiceProvider`](../../../workbench/addons/fixtureaddon/src/FixtureAddonServiceProvider.php).
