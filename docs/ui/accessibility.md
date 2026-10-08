---
title: Accessibility
weight: 39
description: "What the component kit, the panel's pages and an addon's contributions are held to for accessibility, WCAG 2.2 AA, and the tests that hold each of them."
---

# Accessibility

The panel is held to WCAG 2.2 at level AA (GUARDRAILS 8), and the work is split so that the hard parts are done once, in the kit.

## What the kit owns

- **Keyboard and focus.** Every component can be used with the keyboard alone, and its TSDoc states its keyboard contract. The overlays move focus into themselves, keep it there while they are open and give it back to what opened them. The kit builds on React Aria for this behaviour (decision D2).
- **Names.** A component that needs an accessible name requires one in its props: an `IconButton` has no default label, and a field takes its label from the caller.
- **Target size.** Buttons and controls are at least `--cms-target-size`, 24 pixels (2.5.8).
- **Contrast.** Every pair of a text or a user interface part and its surface that the kit uses is listed in `js/ui-kit/tokens.json` with the contrast it must keep, 4.5:1 for text and 3:1 for a user interface part and the focus ring, in the light and the dark mode, and `npm run test:kit -- tokens` fails on a pair below it. `cms:build` checks the same pairs again after it composes the installation's themes.
- **Motion and colours.** Every duration is 0 when the reader prefers reduced motion, and every component has a story in forced colours.
- **Announcements.** A failed read, a refusal and a change of page are announced to a screen reader, with `role="alert"` or a polite live region.

## The tests

| What | Test | Gate |
|---|---|---|
| every story of every component: it renders, its play function runs, axe finds no violation, and it matches its visual baseline | the story tests of the kit's Storybook | 7 |
| every component has the stories `Dark`, `ForcedColors` and `Danish` | `js/ui-kit/tests/story-variants.test.js` | 5 |
| the keyboard contracts of the composite components, such as `Dialog`, `Menu`, `Combobox`, `CommandPalette`, `DataTable` and `Wizard` | `js/ui-kit/tests/keyboard` | 5 |
| every page of the panel: axe finds nothing of any impact, and nothing with every rule of WCAG 2.0, 2.1 and 2.2 at levels A and AA | the shared assertions of `tests/Support/Browser/PanelPage.php` in every browser test of a page | 8 |
| an addon's contribution, in each state its kind renders | `expectNoA11yViolations()` of `@cboxdk/cms-panel/testing`, in the addon's own tests | the addon's CI |

`expectNoA11yViolations()` runs axe on what a contribution rendered with the rules of WCAG 2.2 at levels A and AA. jsdom lays nothing out, so it leaves colour contrast to the browser tests and to the contrast pairs of the tokens.

## What an addon keeps to

An addon builds its UI from the kit's components, which carry the kit's keyboard, focus and name rules, and its texts come from its catalogue, which the lint holds it to. Its styles stay in its own part of the page, so it cannot hide the kit's focus ring on a core control, and a theme it ships must keep every contrast pair. Each example on the [point pages](../addons/panel/points/_index.md) runs `expectNoA11yViolations()` on what the contribution renders.
