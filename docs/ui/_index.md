---
title: The component kit
weight: 35
description: "The component kit of the panel, js/ui-kit: its foundations, design tokens, cascade layers, accessibility and texts, its components by group with their stories, and the headless primitives it builds on."
---

# The component kit

Every component of the panel lives in one kit, the npm workspace `js/ui-kit`. The panel uses its components and never styles the panel's markup itself, and an addon gets the same components through the SDK, `@cboxdk/cms-panel/ui` and `/experimental` ([panel SDK](../addons/panel/sdk.md)).

- [Foundations](foundations.md): the design language, typography, colour modes, motion and the tiers of tokens.
- [Design tokens](tokens.md): every token with its tier and values, the contrast pairs the kit checks, and the curated part hooks.
- [Cascade layers](layers.md): the order of the panel's stylesheets, where an addon's styles and a theme sit, and what each may set.
- [Accessibility](accessibility.md): what the kit, the panel's pages and an addon's contributions are held to, and the tests that hold them.
- [Texts and languages](i18n.md): the catalogues, the rule against literal text, formatting in the reader's locale, and an addon's texts.
- [Components](components.md): every component of the kit, by group, with its stories, and the rules every component follows.

## Primitives

The kit builds on React Aria, which gives it keyboard and focus behaviour and locale-aware formatting (decision D2). React Aria stays inside the kit: no component's props expose it, the kit exports none of it, and the lint refuses an import of it from the panel. The kit imports each React Aria component from its own module, such as `react-aria-components/Dialog`, because the type declarations of a few modules (`Group`, `Popover`, `Tooltip`, `NumberField`, `DatePicker` and `DateRangePicker`) do not compile under the repository's strict TypeScript settings; for those, the kit uses the hooks of `react-aria` and the state of `react-stately` instead. So the overlays that hang from a control (the lists of `Select`, `Combobox` and `MultiSelect`, `Menu` and `Tooltip`) and `NumberInput` are built on the hooks, in a popover of the kit's own, and the dialogs, tabs, tables, trees, check boxes and the command palette on the components.

The kit's popover keeps hanging from its control while it is open. React Aria places it when it opens, when the window resizes and when the popover or the control changes size, but not when the control moves, and a choice can move it with the list still open: the tags a `MultiSelect` adds below its button change the height of a dialog centred on the screen. So after every render in which the control has moved, the popover is placed again. Left where it was, it would hang apart from the control, and React Aria's next placement from its ResizeObserver would change the popover's height inside the observer's callback, which the browser reports as the error "ResizeObserver loop completed with undelivered notifications". `js/ui-kit/tests/overlays/Popover.test.tsx` and the role permissions step of `tests/Browser/Panel/RolesAndGrantsTest.php` hold it.

## Stability and the API report

Every export of the kit carries one stability tag in its TSDoc, `@stable` or `@experimental`. The components of block B1 are experimental (decision D4): their props may change in a minor release. The API report, `js/ui-kit/api/cms-ui-kit.api.md`, lists every export with its declaration and its tag; a test of gate 5 fails when the report differs from what the kit exports, and `npm run api:report` writes it anew after an intended change, which is then reviewed in the diff.
