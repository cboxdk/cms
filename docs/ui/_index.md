---
title: The component kit
weight: 35
description: The component kit of the panel, js/ui-kit, with its design tokens, its cascade layers, its own texts in Danish and English, and the headless primitives it builds on.
---

# The component kit

Every component of the panel lives in one kit, the npm workspace `js/ui-kit`. The panel and, later, an addon's panel code use its components and never style the panel's markup themselves.

- [Design tokens](tokens.md): every token with its tier and values, the contrast pairs the kit checks, and the curated part hooks.

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

The kit builds on React Aria, which gives it keyboard and focus behaviour and locale-aware formatting (decision D2). React Aria stays inside the kit: no component's props expose it, the kit exports none of it, and the lint refuses an import of it from the panel. The kit imports each React Aria component from its own module, such as `react-aria-components/Dialog`, because the type declarations of a few modules (`Group`, `Popover`, `Tooltip`, `NumberField`, `DatePicker` and `DateRangePicker`) do not compile under the repository's strict TypeScript settings; for those, the kit uses the hooks of `react-aria` instead.

## Rules for a component

- It takes no `className` or `style` prop. Its appearance comes from props such as `variant` and `tone`, and from the tokens.
- It reads only semantic and component tokens, never a primitive `--cms-ref-*` one.
- Its markup, class names and attributes are internal. The only exception is a part hook, a `data-cms-part` attribute from the curated list in `tokens.json`.
- Its texts come from the caller's translations or from the kit's catalogues, never from literal text.
- It has a story: a file in `js/ui-kit/stories` whose default export names it as its `component`, with a story for each of its states and a play function for its keyboard contract. Gate 7 fails on a component the kit exports without one, and compares each story with its visual baseline.

## Storybook

The kit's Storybook shows every component in its stories, in the locale and the theme of the toolbar. `npm run storybook` serves it on port 6006. Each story is also a test of gate 7: it renders, its play function runs, axe finds no violation, and a screenshot matches the story's baseline in `js/ui-kit/visual-baselines`, rendered in the dev image. Run them with `composer image:run -- npm run storybook:test`, and after a change meant to change how the kit looks, write the baselines again with `composer image:run -- npm run storybook:baselines`. [Gates and CI](../developers/gates-and-ci.md#storybook-and-gate-7) has the details.
