---
title: Design tokens
weight: 36
description: Every design token of the component kit with its tier, values and stability, the contrast pairs the kit checks in the light and the dark mode, and the curated part hooks.
---

# Design tokens

`npm run generate:tokens` writes this page from `js/ui-kit/tokens.json`, together with `js/ui-kit/src/tokens.css`, the TypeScript names in `js/ui-kit/src/generated/tokens.ts` and the JSON Schema of a theme in `js/ui-kit/src/generated/theme.v1.json`. Gate 6, `composer check:generated`, fails when one of them differs from what the script writes. Edit the JSON and run the script; do not edit the page by hand.

## Tiers

- **Primitive** tokens, named `--cms-ref-*`, are the palette. They are internal: only the kit sets them, and a component never reads one.
- **Semantic** tokens, such as `--cms-color-surface` and `--cms-space-4`, say what a value is for. Components read them, and a theme may set them.
- **Component** tokens, such as `--cms-button-radius`, belong to one component. They are experimental, and a theme may set them.

Each custom property is set on the root in the cascade layer `cms.tokens`. A token with a value per mode takes its dark value when the system prefers dark and the root does not have `data-theme="light"`, or when the root has `data-theme="dark"`. Every duration is 0 when the reader prefers reduced motion.

## Tokens

| Token | Tier | Type | Light | Dark | Stability | Since | Use |
|---|---|---|---|---|---|---|---|
| `--cms-ref-white` | primitive | color | `#ffffff` | `#ffffff` | internal | 1.0 | White. |
| `--cms-ref-gray-50` | primitive | color | `#f6f7f9` | `#f6f7f9` | internal | 1.0 | The lightest grey, for raised surfaces in the light mode. |
| `--cms-ref-gray-100` | primitive | color | `#e8eaee` | `#e8eaee` | internal | 1.0 | A light grey, for text in the dark mode. |
| `--cms-ref-gray-200` | primitive | color | `#d9dde3` | `#d9dde3` | internal | 1.0 | A light grey, for quiet borders in the light mode. |
| `--cms-ref-gray-400` | primitive | color | `#9aa1ad` | `#9aa1ad` | internal | 1.0 | A middle grey, for secondary text in the dark mode. |
| `--cms-ref-gray-500` | primitive | color | `#8a919e` | `#8a919e` | internal | 1.0 | A middle grey, for the borders of controls in the light mode. |
| `--cms-ref-gray-550` | primitive | color | `#6b7280` | `#6b7280` | internal | 1.0 | A middle grey, for the borders of controls in the dark mode. |
| `--cms-ref-gray-600` | primitive | color | `#5b6270` | `#5b6270` | internal | 1.0 | A dark grey, for secondary text in the light mode. |
| `--cms-ref-gray-800` | primitive | color | `#2e333c` | `#2e333c` | internal | 1.0 | A dark grey, for quiet borders in the dark mode. |
| `--cms-ref-gray-900` | primitive | color | `#16181d` | `#16181d` | internal | 1.0 | A near black, for text in the light mode. |
| `--cms-ref-gray-925` | primitive | color | `#1b1e24` | `#1b1e24` | internal | 1.0 | A near black, for raised surfaces in the dark mode. |
| `--cms-ref-gray-950` | primitive | color | `#121418` | `#121418` | internal | 1.0 | A near black, for the surface in the dark mode. |
| `--cms-ref-gray-1000` | primitive | color | `#0c0e12` | `#0c0e12` | internal | 1.0 | The darkest grey, for text on the accent in the dark mode. |
| `--cms-ref-blue-300` | primitive | color | `#87a4f4` | `#87a4f4` | internal | 1.0 | A light blue, for the hovered accent in the dark mode. |
| `--cms-ref-blue-400` | primitive | color | `#6b8ff0` | `#6b8ff0` | internal | 1.0 | A blue, for the accent in the dark mode. |
| `--cms-ref-blue-600` | primitive | color | `#2f5bd3` | `#2f5bd3` | internal | 1.0 | A blue, for the accent in the light mode. |
| `--cms-ref-blue-700` | primitive | color | `#2349b0` | `#2349b0` | internal | 1.0 | A dark blue, for the hovered accent in the light mode. |
| `--cms-ref-red-400` | primitive | color | `#ef6b66` | `#ef6b66` | internal | 1.0 | A light red, for danger in the dark mode. |
| `--cms-ref-red-600` | primitive | color | `#c4302b` | `#c4302b` | internal | 1.0 | A red, for danger in the light mode. |
| `--cms-ref-black` | primitive | color | `#000000` | `#000000` | internal | 1.0 | Black, for the backdrop behind a dialog. |
| `--cms-ref-gray-300` | primitive | color | `#c3c9d2` | `#c3c9d2` | internal | 1.0 | A light grey, for the shadow of an overlay in the light mode. |
| `--cms-ref-blue-50` | primitive | color | `#e9effc` | `#e9effc` | internal | 1.0 | The palest blue, for a selected item in the light mode. |
| `--cms-ref-blue-900` | primitive | color | `#1d2a4a` | `#1d2a4a` | internal | 1.0 | A dark blue, for a selected item in the dark mode. |
| `--cms-ref-green-400` | primitive | color | `#4cc07c` | `#4cc07c` | internal | 1.0 | A light green, for success in the dark mode. |
| `--cms-ref-green-600` | primitive | color | `#1d7a43` | `#1d7a43` | internal | 1.0 | A green, for success in the light mode. |
| `--cms-ref-amber-400` | primitive | color | `#e8a53b` | `#e8a53b` | internal | 1.0 | A light amber, for warnings in the dark mode. |
| `--cms-ref-amber-700` | primitive | color | `#a15c00` | `#a15c00` | internal | 1.0 | A dark amber, for warnings in the light mode. |
| `--cms-color-surface` | semantic | color | `#ffffff` (`var(--cms-ref-white)`) | `#121418` (`var(--cms-ref-gray-950)`) | stable | 1.0 | The background of the page and of controls. |
| `--cms-color-surface-raised` | semantic | color | `#f6f7f9` (`var(--cms-ref-gray-50)`) | `#1b1e24` (`var(--cms-ref-gray-925)`) | stable | 1.0 | The background of a panel or a button that sits on the surface. |
| `--cms-color-text` | semantic | color | `#16181d` (`var(--cms-ref-gray-900)`) | `#e8eaee` (`var(--cms-ref-gray-100)`) | stable | 1.0 | Body text and headings. |
| `--cms-color-text-muted` | semantic | color | `#5b6270` (`var(--cms-ref-gray-600)`) | `#9aa1ad` (`var(--cms-ref-gray-400)`) | stable | 1.0 | Secondary text, such as a field's hint or a page's description. |
| `--cms-color-border` | semantic | color | `#d9dde3` (`var(--cms-ref-gray-200)`) | `#2e333c` (`var(--cms-ref-gray-800)`) | stable | 1.0 | Borders that only group content and carry no meaning of their own. |
| `--cms-color-border-strong` | semantic | color | `#8a919e` (`var(--cms-ref-gray-500)`) | `#6b7280` (`var(--cms-ref-gray-550)`) | stable | 1.0 | The border of a control, which shows where it is, so it meets the contrast of a user interface part. |
| `--cms-color-accent` | semantic | color | `#2f5bd3` (`var(--cms-ref-blue-600)`) | `#6b8ff0` (`var(--cms-ref-blue-400)`) | stable | 1.0 | The colour of links and of the primary action. |
| `--cms-color-accent-hover` | semantic | color | `#2349b0` (`var(--cms-ref-blue-700)`) | `#87a4f4` (`var(--cms-ref-blue-300)`) | stable | 1.0 | The accent under the pointer. |
| `--cms-color-on-accent` | semantic | color | `#ffffff` (`var(--cms-ref-white)`) | `#0c0e12` (`var(--cms-ref-gray-1000)`) | stable | 1.0 | Text on the accent, such as the label of a primary button. |
| `--cms-color-danger` | semantic | color | `#c4302b` (`var(--cms-ref-red-600)`) | `#ef6b66` (`var(--cms-ref-red-400)`) | stable | 1.0 | Errors and destructive actions. |
| `--cms-color-focus` | semantic | color | `#2f5bd3` (`var(--cms-color-accent)`) | `#6b8ff0` (`var(--cms-color-accent)`) | stable | 1.0 | The colour of the focus ring. |
| `--cms-color-accent-subtle` | semantic | color | `#e9effc` (`var(--cms-ref-blue-50)`) | `#1d2a4a` (`var(--cms-ref-blue-900)`) | stable | 1.0 | The background of a selected item, such as the current entry of the navigation. |
| `--cms-color-success` | semantic | color | `#1d7a43` (`var(--cms-ref-green-600)`) | `#4cc07c` (`var(--cms-ref-green-400)`) | stable | 1.0 | Success, such as a committed change. |
| `--cms-color-warning` | semantic | color | `#a15c00` (`var(--cms-ref-amber-700)`) | `#e8a53b` (`var(--cms-ref-amber-400)`) | stable | 1.0 | A warning, such as a change that waits for a projection. |
| `--cms-color-backdrop` | semantic | color | `#000000` (`var(--cms-ref-black)`) | `#000000` (`var(--cms-ref-black)`) | stable | 1.0 | The colour of the backdrop behind a dialog, shown partly transparent. |
| `--cms-shadow-overlay` | semantic | shadow | `0 4px 16px #c3c9d2` (`0 4px 16px var(--cms-ref-gray-300)`) | `0 4px 16px #000000` (`0 4px 16px var(--cms-ref-black)`) | stable | 1.0 | The shadow of an overlay, such as a menu or a dialog. |
| `--cms-font-family` | semantic | font-family | `system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` | `system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` | stable | 1.0 | The typeface of all text. |
| `--cms-font-family-mono` | semantic | font-family | `ui-monospace, 'SF Mono', Menlo, Consolas, 'DejaVu Sans Mono', monospace` | `ui-monospace, 'SF Mono', Menlo, Consolas, 'DejaVu Sans Mono', monospace` | stable | 1.0 | The typeface of codes and identifiers. |
| `--cms-font-size-sm` | semantic | length | `0.8125rem` | `0.8125rem` | stable | 1.0 | Small text, such as a status code. |
| `--cms-font-size-md` | semantic | length | `0.875rem` | `0.875rem` | stable | 1.0 | The text of controls, labels and hints. |
| `--cms-font-size-lg` | semantic | length | `1rem` | `1rem` | stable | 1.0 | Body text and the value of an input. |
| `--cms-font-size-xl` | semantic | length | `1.375rem` | `1.375rem` | stable | 1.0 | A page's heading. |
| `--cms-font-weight-regular` | semantic | number | `400` | `400` | stable | 1.0 | The weight of body text. |
| `--cms-font-weight-medium` | semantic | number | `500` | `500` | stable | 1.0 | The weight of labels, buttons and links. |
| `--cms-font-weight-semibold` | semantic | number | `600` | `600` | stable | 1.0 | The weight of headings. |
| `--cms-line-height` | semantic | number | `1.5` | `1.5` | stable | 1.0 | The line height of body text. |
| `--cms-line-height-tight` | semantic | number | `1.25` | `1.25` | stable | 1.0 | The line height of headings. |
| `--cms-space-1` | semantic | length | `0.25rem` | `0.25rem` | stable | 1.0 | The smallest space, such as between a label and its input. |
| `--cms-space-2` | semantic | length | `0.5rem` | `0.5rem` | stable | 1.0 | A small space, such as inside a button. |
| `--cms-space-3` | semantic | length | `0.75rem` | `0.75rem` | stable | 1.0 | A space between related items. |
| `--cms-space-4` | semantic | length | `1rem` | `1rem` | stable | 1.0 | The space between the fields of a form. |
| `--cms-space-6` | semantic | length | `1.5rem` | `1.5rem` | stable | 1.0 | The space between the parts of a panel. |
| `--cms-space-8` | semantic | length | `2rem` | `2rem` | stable | 1.0 | The largest space, such as the padding of a panel. |
| `--cms-radius-sm` | semantic | length | `4px` | `4px` | stable | 1.0 | The rounding of small parts, such as the focus ring of a link. |
| `--cms-radius-md` | semantic | length | `6px` | `6px` | stable | 1.0 | The rounding of controls. |
| `--cms-radius-lg` | semantic | length | `10px` | `10px` | stable | 1.0 | The rounding of panels. |
| `--cms-focus-ring` | semantic | shadow | `0 0 0 2px #ffffff, 0 0 0 4px #2f5bd3` (`0 0 0 2px var(--cms-color-surface), 0 0 0 4px var(--cms-color-focus)`) | `0 0 0 2px #121418, 0 0 0 4px #6b8ff0` (`0 0 0 2px var(--cms-color-surface), 0 0 0 4px var(--cms-color-focus)`) | stable | 1.0 | The focus ring of every control: a gap in the surface's colour and a ring in the focus colour. |
| `--cms-duration-fast` | semantic | duration | `120ms` | `120ms` | stable | 1.0 | Short transitions, such as a hover. It is 0 when the reader prefers reduced motion. |
| `--cms-target-size` | semantic | length | `1.5rem` | `1.5rem` | stable | 1.0 | The smallest height of a pointer target: at least 24 pixels (WCAG 2.2, 2.5.8). |
| `--cms-measure` | semantic | length | `32rem` | `32rem` | stable | 1.0 | The widest a panel of running text grows. |
| `--cms-button-radius` | component | length | `6px` (`var(--cms-radius-md)`) | `6px` (`var(--cms-radius-md)`) | experimental | 1.0 | The rounding of a button. |
| `--cms-brand-logo-height` | component | length | `2rem` | `2rem` | experimental | 1.0 | The height of the installation's logo next to its name, in the shell's header and on the login page. |
| `--cms-nav-width` | component | length | `16rem` | `16rem` | experimental | 1.0 | The width of the side navigation of the panel on a wide screen. |
| `--cms-table-row-height` | component | length | `2.75rem` | `2.75rem` | experimental | 1.0 | The smallest height of a row of a data table. |
| `--cms-dialog-width` | component | length | `32rem` | `32rem` | experimental | 1.0 | The widest a dialog grows. |
| `--cms-drawer-width` | component | length | `28rem` | `28rem` | experimental | 1.0 | The widest a drawer grows. |
| `--cms-palette-width` | component | length | `40rem` | `40rem` | experimental | 1.0 | The widest the command palette grows. |

## Contrast pairs

Every pair of a foreground and a background the kit draws is listed here. A text pair must reach 4.50:1 and a pair of a user interface part or the focus ring 3.00:1 (WCAG 2.2, 1.4.3 and 1.4.11), in both modes. `npm run test:kit -- tokens` fails when one does not, and so does `composer check`.

| Foreground | Background | Kind | Minimum | Light | Dark |
|---|---|---|---|---|---|
| `--cms-color-text` | `--cms-color-surface` | text | 4.50:1 | 17.75:1 | 15.30:1 |
| `--cms-color-text` | `--cms-color-surface-raised` | text | 4.50:1 | 16.56:1 | 13.86:1 |
| `--cms-color-text-muted` | `--cms-color-surface` | text | 4.50:1 | 6.12:1 | 7.09:1 |
| `--cms-color-text-muted` | `--cms-color-surface-raised` | text | 4.50:1 | 5.71:1 | 6.42:1 |
| `--cms-color-accent` | `--cms-color-surface` | text | 4.50:1 | 5.90:1 | 5.98:1 |
| `--cms-color-accent` | `--cms-color-surface-raised` | text | 4.50:1 | 5.50:1 | 5.41:1 |
| `--cms-color-accent-hover` | `--cms-color-surface` | text | 4.50:1 | 7.92:1 | 7.59:1 |
| `--cms-color-accent-hover` | `--cms-color-surface-raised` | text | 4.50:1 | 7.38:1 | 6.87:1 |
| `--cms-color-on-accent` | `--cms-color-accent` | text | 4.50:1 | 5.90:1 | 6.26:1 |
| `--cms-color-on-accent` | `--cms-color-accent-hover` | text | 4.50:1 | 7.92:1 | 7.95:1 |
| `--cms-color-danger` | `--cms-color-surface` | text | 4.50:1 | 5.51:1 | 6.11:1 |
| `--cms-color-danger` | `--cms-color-surface-raised` | text | 4.50:1 | 5.14:1 | 5.53:1 |
| `--cms-color-border-strong` | `--cms-color-surface` | ui | 3.00:1 | 3.17:1 | 3.81:1 |
| `--cms-color-focus` | `--cms-color-surface` | ui | 3.00:1 | 5.90:1 | 5.98:1 |
| `--cms-color-focus` | `--cms-color-surface-raised` | ui | 3.00:1 | 5.50:1 | 5.41:1 |
| `--cms-color-success` | `--cms-color-surface` | text | 4.50:1 | 5.36:1 | 8.02:1 |
| `--cms-color-success` | `--cms-color-surface-raised` | text | 4.50:1 | 5.00:1 | 7.26:1 |
| `--cms-color-warning` | `--cms-color-surface` | text | 4.50:1 | 5.18:1 | 8.67:1 |
| `--cms-color-warning` | `--cms-color-surface-raised` | text | 4.50:1 | 4.84:1 | 7.85:1 |
| `--cms-color-accent` | `--cms-color-accent-subtle` | text | 4.50:1 | 5.12:1 | 4.59:1 |
| `--cms-color-text` | `--cms-color-accent-subtle` | text | 4.50:1 | 15.40:1 | 11.76:1 |
| `--cms-color-text-muted` | `--cms-color-accent-subtle` | text | 4.50:1 | 5.31:1 | 5.44:1 |

## Part hooks

A part hook is a `data-cms-part` attribute on a curated element of the kit. The markup, the class names and every other attribute of a component are internal; only a theme, in the cascade layer `cms.theme`, may target a part hook, and only to set tokens for that part. Every hook is experimental.

| Part | Stability | Since | Element |
|---|---|---|---|
| `status-screen` | experimental | 1.0 | The panel of a status page, such as the page for an address the panel does not have. |
| `task-screen` | experimental | 1.0 | The panel of a page for one task outside the panel's navigation, such as signing in. |
