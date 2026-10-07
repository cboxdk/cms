---
title: Panel SDK
weight: 52
description: "The npm package @cboxdk/cms-panel an addon builds its panel UI with: its subpaths, definePanelAddon and usePanelHost, the types cms:panel:types writes from the manifest, and the API report that records what addons may rely on."
---

# Panel SDK

An addon builds its panel UI against one npm package, `@cboxdk/cms-panel`, in the workspace `js/panel-sdk` (decision D1 of the panel extension architecture). The package is private in this repository until its release is decided; its subpaths point at the TypeScript sources, as the component kit's do. The panel's own app is the private workspace `js/panel`, `@cboxdk/cms-panel-app`, which is never published.

At run time an addon never ships a second copy of the SDK, the kit or React: the panel's import map hands an addon the panel's own modules, and the build plugin leaves them external. The npm copy gives an addon its types, its build plugin, its test helpers and its lint configurations.

## Subpaths

- `@cboxdk/cms-panel/ui`: the component kit's stable API.
- `@cboxdk/cms-panel/extend`: the stable panel API: `definePanelAddon`, `usePanelHost`, the type of each kind of contribution (`SlotComponent`, `PageComponent`, `Decorator`, `Replacement` with `ReplacementProps`, which take the data a replacement with a query gets as a slot does, `FormCheck`, `FlowStep`, `Observer`, `Provider`, each lazily imported as `Lazy<...>` where it is a component), the descriptors a slot in a structured region takes (`ToolbarItem`, `ColumnDescriptor` and `TabDescriptor`, each the default export of its module), the JSON value types, the receipt, the summary of a dry run and the problem details the host answers with (`CommandAnswer`), and the props of the stable panel points.
- `@cboxdk/cms-panel/experimental`: the experimental API, which may change in a minor version of the panel API: the props of the experimental points, the kit's experimental components, the experimental kinds of contribution, `ActionHandler` and `AsyncFormCheck`, and what a replacement of a field's input in the [command form](command-form.md) takes: `FieldInputProps`, the props of `command.form.field@1` with `onChange`, `FieldInput`, and `JsonDocument`, a JSON document of another contract a point's props hold, such as the receipt of `command.form.receipt@1`. Importing it is half of an addon's opt-in to an experimental point; the other half is the point in its manifest's `acceptsExperimental`. Every point and kit component of block B1 is experimental (decision D4).
- `@cboxdk/cms-panel/tokens.css`: the panel's cascade layers in their order and its design tokens, for an addon's stories and tests.
- `@cboxdk/cms-panel/vite`: the build plugin. It leaves `react`, `react/jsx-runtime`, `react-dom`, `react-dom/client` and `@cboxdk/cms-panel` with its subpaths external, and fails the build on an import of Inertia, React Aria, the kit's own package `@cboxdk/cms-ui-kit` or a module from a URL.
- `@cboxdk/cms-panel/testing`: `PanelHostProvider`, which renders a contribution with a host a test builds.
- `@cboxdk/cms-panel/eslint`: the lint of an addon's panel code, on the panel's own rules, with the imports the panel keeps to itself refused and `no-deprecated` on.
- `@cboxdk/cms-panel/stylelint`: a stylelint configuration of stylelint's own rules that keeps an addon's styles on the tokens: no `!important`, no colour of its own, no id selector, no `url()` and no `@import`.
- `@cboxdk/cms-panel/storybook`: the Storybook preset that renders an addon's stories in the panel's layers, tokens, locale and theme, with axe.

Which subpath exports what is a rule, not a habit: `/experimental` exports only `@experimental` API and every other subpath only `@stable` API. `npm run generate:sdk` writes the kit's part of `/ui` and `/experimental` from the stability tags of the kit's exports, `js/panel-sdk/src/kit/stable.ts` and `experimental.ts`, and `composer generate:protocol` writes the points' part from each point's stability, `js/panel-sdk/src/generated/stable.ts` and `experimental.ts`. A test of gate 5 fails when a committed file differs from what its generator writes.

## Registering an addon's contributions

The default export of an addon's bundle entry is `definePanelAddon<Contributions>({...})`: a map of each contribution of its manifest that runs code, by id, to what its kind takes, a component as `() => import('./Module')` and a check, decorator or observer as the function itself. At run time it holds the contributions frozen, with their ids sorted and the panel API version, and refuses an id that is not the addon's namespace and a name, a value that is not a function, and the ids of two namespaces.

A contribution reaches the panel only through `usePanelHost()`: texts and formatting in the panel's locale, notices, navigation to the panel's pages, dialogs, and `runCommand()` for the commands the manifest's `issues` lists, each sent with the provenance `addon:<namespace>:<contribution>` and answered with the receipt, the summary of a dry run (`dryRun`, for a call with `dryRun: true` that was not rejected) and the problem details of a rejection. `usePanelHost<Issues>()` takes only those, each with its own document. Outside the panel it throws `PanelHostMissing`.

## The types cms:panel:types writes

`cms:panel:types <namespace>` writes `resources/panel/generated/contributions.ts` below the addon's Composer package, from the contributions `cms:build` compiled from its manifest, so run `cms:build` first. The module has:

- `Contributions`, with a member per contribution that runs code, by id, of its kind's type on its point's props and its data query's result or its form's command document;
- `Issues`, with a member per command the addon may issue, by name and version, of its document;
- the types of those documents, from the JSON Schemas of the commands' and queries' codecs, named after the command or query and its version, such as `ReviewsRequestV1` and `ReviewsPendingResultV1`.

A stable point's props are imported from `/extend` and an experimental point's from `/experimental`. tsc in the addon's repository then fails on a registration with a missing key, an extra key or a component of other props. An addon without a contribution that runs code gets `NoContributions`, and one that issues no command `NoCommands`. The command writes the file only when its bytes differ and removes any other file in that directory, so a second run changes nothing and the addon's own `check:generated` can keep it current. It exits 64 for a namespace no installed addon has, 65 for a schema with a structure TypeScript cannot type, such as `patternProperties`, 66 for a command or query without a codec, 73 when it cannot write, and 78 when the registry cannot be read; the codes are in [Error codes](../reference/errors.md).

## The API report

`js/panel-sdk/api/cms-panel.api.md` is the API Extractor report of every subpath, each export with its stability tag. A test of gate 5 fails when it is not what the SDK exports now, so a change to what addons may rely on is recorded with its version decision: write the report anew with `npm run api:report`, which writes the kit's report too, and review the diff. Adding an export is a minor version of the panel API; removing, renaming or narrowing a stable one is a major version. The SDK's `PANEL_API_VERSION` is the PHP side's `PanelApiVersion::current()`, which `cms:build` checks an addon's `sdk` against, and a test holds the two equal.
