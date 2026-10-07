---
title: The component kit
weight: 35
description: The component kit of the panel, js/ui-kit, with its design tokens, its cascade layers, its own texts in Danish and English, and the headless primitives it builds on.
---

# The component kit

Every component of the panel lives in one kit, the npm workspace `js/ui-kit`. The panel and, later, an addon's panel code use its components and never style the panel's markup themselves.

- [Components](components.md): every component of the kit, by group, with what it is for.
- [Design tokens](tokens.md): every token with its tier and values, the contrast pairs the kit checks, and the curated part hooks.

## Design language

The kit follows the Cbox design language of `@cboxdk/cbox-ui` (MIT), a dependency of `js/ui-kit`: its palette in `tokens/cbox.css`, refined and minimal, with a deep blue primary, a cool canvas, white cards with a 1px border and a soft shadow, and soft tones for messages. The kit's tokens take their values from that file; where a pair of the kit's contrast checks needs more contrast than a cbox-ui value gives, such as the text of a tone or the border of a control, the kit uses the same hue at another lightness, and each primitive's description in [Design tokens](tokens.md) says which. `npm run test:kit -- brand` holds the copied values to the installed `tokens/cbox.css`.

Text and headings are set in Plus Jakarta Sans and code, ids and numbers in JetBrains Mono, both variable fonts under the SIL Open Font License 1.1 from `@fontsource-variable/plus-jakarta-sans` and `@fontsource-variable/jetbrains-mono`. `base.css` declares their Latin and Latin Extended faces, and the panel's build bundles the files, so they come from the panel's own origin. A system face is only the fallback after them.

A message, a card or a panel never marks its tone with a coloured stripe at its edge: a message shows its tone with its soft background, its icon and its text, and says what to do next. The same test fails on a `border-left`, a `border-inline-start` or an inset shadow at that edge in a tone in any stylesheet of the kit or the panel.

## Cascade layers

`js/ui-kit/src/layers.css` declares the order of the cascade layers once, and a page imports it before any other stylesheet. A later layer wins over an earlier one, whatever the specificity of the selectors:

1. `cms.reset`: the document styles of `base.css`.
2. `cms.tokens`: the design tokens of `tokens.css`.
3. `cms.addon`: the styles of an addon, each scoped to the addon's own part of the page, so an addon cannot restyle the kit or the panel.
4. `cms.kit`: the components of the kit.
5. `cms.panel`: the panel's own pages.
6. `cms.theme`: the themes the application selects, the only layer that may set tokens or target a part hook.

Every stylesheet of the kit keeps all its rules in its layer and uses no `!important`; `npm run test:kit -- layers` fails otherwise.

## Texts

The kit has a few texts of its own, such as the mark of a required field. They live in its catalogues, `js/ui-kit/src/i18n/catalogues/da.json` and `en.json`, with the same keys under `kit.`. A page renders the kit inside `KitI18nProvider` with its locale, `da` or `en`; without one, the kit speaks English. The provider also gives React Aria the same locale, so dates and numbers follow it. Every other text comes from the caller's own translations, and the lint rule against literal text holds the kit to that as it holds the panel.

## Primitives

The kit builds on React Aria, which gives it keyboard and focus behaviour and locale-aware formatting (decision D2). React Aria stays inside the kit: no component's props expose it, the kit exports none of it, and the lint refuses an import of it from the panel. The kit imports each React Aria component from its own module, such as `react-aria-components/Dialog`, because the type declarations of a few modules (`Group`, `Popover`, `Tooltip`, `NumberField`, `DatePicker` and `DateRangePicker`) do not compile under the repository's strict TypeScript settings; for those, the kit uses the hooks of `react-aria` and the state of `react-stately` instead. So the overlays that hang from a control (the lists of `Select`, `Combobox` and `MultiSelect`, `Menu` and `Tooltip`) and `NumberInput` are built on the hooks, in a popover of the kit's own, and the dialogs, tabs, tables, trees, check boxes and the command palette on the components.

The kit's popover keeps hanging from its control while it is open. React Aria places it when it opens, when the window resizes and when the popover or the control changes size, but not when the control moves, and a choice can move it with the list still open: the tags a `MultiSelect` adds below its button change the height of a dialog centred on the screen. So after every render in which the control has moved, the popover is placed again. Left where it was, it would hang apart from the control, and React Aria's next placement from its ResizeObserver would change the popover's height inside the observer's callback, which the browser reports as the error "ResizeObserver loop completed with undelivered notifications". `js/ui-kit/tests/overlays/Popover.test.tsx` and the role permissions step of `tests/Browser/Panel/RolesAndGrantsTest.php` hold it.

## Stability and the API report

Every export of the kit carries one stability tag in its TSDoc, `@stable` or `@experimental`. The components of block B1 are experimental (decision D4): their props may change in a minor release. The API report, `js/ui-kit/api/cms-ui-kit.api.md`, lists every export with its declaration and its tag; a test of gate 5 fails when the report differs from what the kit exports, and `npm run api:report` writes it anew after an intended change, which is then reviewed in the diff.

## Rules for a component

- It takes no `className` or `style` prop. Its appearance comes from props such as `variant` and `tone`, and from the tokens.
- It reads only semantic and component tokens, never a primitive `--cms-ref-*` one.
- Its markup, class names and attributes are internal. The only exception is a part hook, a `data-cms-part` attribute from the curated list in `tokens.json`.
- Its texts come from the caller's translations or from the kit's catalogues, never from literal text.
- It carries a stability tag, and its TSDoc says what it is for and its keyboard contract.
- A component that shows data has an empty, a loading and a failed state, each with a story: what it waits for is said in a `ProgressLabel`, and a failure and an empty list say what to do next.
- It has a story: a file in `js/ui-kit/stories` whose default export names it as its `component`, with a story for each of its states and a play function for its keyboard contract, and the stories `Dark`, `ForcedColors` and `Danish`. Gate 7 fails on a component the kit exports without one, and compares each story with its visual baseline.
- It has an MDX page beside its stories, `js/ui-kit/stories/<Name>.mdx`: its stability and since, its description from its TSDoc, do and don't, and its props.

## Storybook

The kit's Storybook shows every component in its stories, in the locale and the theme of the toolbar. `npm run storybook` serves it on port 6006. Each story is also a test of gate 7: it renders, its play function runs, axe finds no violation, and a screenshot matches the story's baseline in `js/ui-kit/visual-baselines`, rendered in the dev image. Run them with `composer image:run -- npm run storybook:test`, and after a change meant to change how the kit looks, write the baselines again with `composer image:run -- npm run storybook:baselines`. [Gates and CI](../developers/gates-and-ci.md#storybook-and-gate-7) has the details.
