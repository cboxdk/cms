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
| `--cms-ref-white` | primitive | color | `#ffffff` | `#ffffff` | internal | 1.0 | White: the card in the light mode, --card of @cboxdk/cbox-ui. |
| `--cms-ref-gray-10` | primitive | color | `oklch(99% 0.003 247)` | `oklch(99% 0.003 247)` | internal | 1.0 | The near white on the accent in the light mode, --primary-foreground of @cboxdk/cbox-ui. |
| `--cms-ref-gray-25` | primitive | color | `oklch(99.2% 0.003 247)` | `oklch(99.2% 0.003 247)` | internal | 1.0 | The cool off-white of the page in the light mode, --background of @cboxdk/cbox-ui. |
| `--cms-ref-gray-50` | primitive | color | `oklch(97.5% 0.008 250)` | `oklch(97.5% 0.008 250)` | internal | 1.0 | The cool grey of the canvas and raised surfaces in the light mode, --canvas of @cboxdk/cbox-ui. |
| `--cms-ref-gray-75` | primitive | color | `oklch(96% 0.006 250)` | `oklch(96% 0.006 250)` | internal | 1.0 | A light cool grey, for muted fills such as a tile on a card in the light mode, --muted of @cboxdk/cbox-ui. |
| `--cms-ref-gray-150` | primitive | color | `#e3e7ec` | `#e3e7ec` | internal | 1.0 | A faint grey, the one-pixel shadow under a card in the light mode: --foreground of @cboxdk/cbox-ui at 7 % over the canvas. |
| `--cms-ref-gray-200` | primitive | color | `oklch(92% 0.006 250)` | `oklch(92% 0.006 250)` | internal | 1.0 | A light grey, for quiet borders in the light mode, --border of @cboxdk/cbox-ui. |
| `--cms-ref-gray-300` | primitive | color | `#ced2d9` | `#ced2d9` | internal | 1.0 | A light blue grey, the shadow of an overlay in the light mode: the shadow colour of the cbox-ui mockups at 16 % over the canvas. |
| `--cms-ref-gray-450` | primitive | color | `oklch(64% 0.012 250)` | `oklch(64% 0.012 250)` | internal | 1.0 | A middle grey of the cbox-ui hue, for the borders of controls in the light mode, dark enough for 3:1 against the page. |
| `--cms-ref-gray-600` | primitive | color | `oklch(50% 0.012 250)` | `oklch(50% 0.012 250)` | internal | 1.0 | A dark grey, for secondary text in the light mode, --muted-foreground of @cboxdk/cbox-ui. |
| `--cms-ref-gray-900` | primitive | color | `oklch(18% 0.012 250)` | `oklch(18% 0.012 250)` | internal | 1.0 | A near black, for text in the light mode, --foreground of @cboxdk/cbox-ui. |
| `--cms-ref-gray-100` | primitive | color | `oklch(97% 0.005 247)` | `oklch(97% 0.005 247)` | internal | 1.0 | A near white, for text in the dark mode, --foreground of @cboxdk/cbox-ui. |
| `--cms-ref-gray-350` | primitive | color | `oklch(70% 0.012 250)` | `oklch(70% 0.012 250)` | internal | 1.0 | A light grey, for secondary text in the dark mode, --muted-foreground of @cboxdk/cbox-ui. |
| `--cms-ref-gray-550` | primitive | color | `oklch(52% 0.014 250)` | `oklch(52% 0.014 250)` | internal | 1.0 | A middle grey of the cbox-ui hue, for the borders of controls in the dark mode, light enough for 3:1 against the page. |
| `--cms-ref-gray-800` | primitive | color | `oklch(28% 0.012 250)` | `oklch(28% 0.012 250)` | internal | 1.0 | A dark grey, for quiet borders in the dark mode, --border of @cboxdk/cbox-ui. |
| `--cms-ref-gray-850` | primitive | color | `oklch(24% 0.012 250)` | `oklch(24% 0.012 250)` | internal | 1.0 | A dark grey, for muted fills such as a tile on a card in the dark mode, --muted of @cboxdk/cbox-ui. |
| `--cms-ref-gray-925` | primitive | color | `oklch(20% 0.01 250)` | `oklch(20% 0.01 250)` | internal | 1.0 | A near black, for cards and raised surfaces in the dark mode, --card of @cboxdk/cbox-ui. |
| `--cms-ref-gray-940` | primitive | color | `oklch(18% 0.01 250)` | `oklch(18% 0.01 250)` | internal | 1.0 | A near black, for the canvas in the dark mode, --canvas of @cboxdk/cbox-ui. |
| `--cms-ref-gray-950` | primitive | color | `oklch(15.5% 0.01 250)` | `oklch(15.5% 0.01 250)` | internal | 1.0 | A near black, for the page in the dark mode, --background of @cboxdk/cbox-ui. |
| `--cms-ref-gray-1000` | primitive | color | `oklch(16% 0.01 250)` | `oklch(16% 0.01 250)` | internal | 1.0 | A near black, for text on the accent in the dark mode, --primary-foreground of @cboxdk/cbox-ui. |
| `--cms-ref-blue-50` | primitive | color | `#e9f0f9` | `#e9f0f9` | internal | 1.0 | The soft accent in the light mode: --accent-soft of @cboxdk/cbox-ui over the page. |
| `--cms-ref-blue-300` | primitive | color | `oklch(72% 0.16 258)` | `oklch(72% 0.16 258)` | internal | 1.0 | A light blue of the cbox-ui hue, for the hovered accent in the dark mode. |
| `--cms-ref-blue-400` | primitive | color | `oklch(65% 0.18 258)` | `oklch(65% 0.18 258)` | internal | 1.0 | The deep blue accent of the dark mode, --primary of @cboxdk/cbox-ui. |
| `--cms-ref-blue-600` | primitive | color | `oklch(45% 0.16 258)` | `oklch(45% 0.16 258)` | internal | 1.0 | The deep blue accent of the light mode, --primary of @cboxdk/cbox-ui. |
| `--cms-ref-blue-700` | primitive | color | `oklch(41% 0.16 258)` | `oklch(41% 0.16 258)` | internal | 1.0 | A darker blue of the cbox-ui hue, for the hovered accent in the light mode, as the cbox-ui mockups hover the primary button. |
| `--cms-ref-blue-900` | primitive | color | `#13243a` | `#13243a` | internal | 1.0 | The soft accent in the dark mode: --accent-soft of @cboxdk/cbox-ui over the page. |
| `--cms-ref-red-50` | primitive | color | `#f7e4e6` | `#f7e4e6` | internal | 1.0 | The soft danger tone in the light mode: --destructive-soft of @cboxdk/cbox-ui over the page. |
| `--cms-ref-red-400` | primitive | color | `oklch(68% 0.21 25)` | `oklch(68% 0.21 25)` | internal | 1.0 | A light red, for danger in the dark mode, --destructive of @cboxdk/cbox-ui. |
| `--cms-ref-red-600` | primitive | color | `oklch(48% 0.19 25)` | `oklch(48% 0.19 25)` | internal | 1.0 | A dark red of the cbox-ui hue, for danger in the light mode, the text tone the cbox-ui mockups use on the soft danger tone. |
| `--cms-ref-red-900` | primitive | color | `#30181a` | `#30181a` | internal | 1.0 | The soft danger tone in the dark mode: --destructive-soft of @cboxdk/cbox-ui over the page. |
| `--cms-ref-black` | primitive | color | `#000000` | `#000000` | internal | 1.0 | Black. |
| `--cms-ref-green-50` | primitive | color | `#e4f2e8` | `#e4f2e8` | internal | 1.0 | The soft success tone in the light mode: --success-soft of @cboxdk/cbox-ui over the page. |
| `--cms-ref-green-400` | primitive | color | `oklch(74% 0.16 145)` | `oklch(74% 0.16 145)` | internal | 1.0 | A light green, for success in the dark mode, --success of @cboxdk/cbox-ui. |
| `--cms-ref-green-600` | primitive | color | `oklch(45% 0.13 145)` | `oklch(45% 0.13 145)` | internal | 1.0 | A dark green of the cbox-ui hue, for success in the light mode, the text tone the cbox-ui mockups use on the soft success tone. |
| `--cms-ref-green-900` | primitive | color | `#172a1e` | `#172a1e` | internal | 1.0 | The soft success tone in the dark mode: --success-soft of @cboxdk/cbox-ui over the page. |
| `--cms-ref-amber-50` | primitive | color | `#f8eeda` | `#f8eeda` | internal | 1.0 | The soft warning tone in the light mode: --warning-soft of @cboxdk/cbox-ui over the page. |
| `--cms-ref-amber-400` | primitive | color | `oklch(78% 0.16 70)` | `oklch(78% 0.16 70)` | internal | 1.0 | A light amber, for warnings in the dark mode, --warning of @cboxdk/cbox-ui. |
| `--cms-ref-amber-700` | primitive | color | `oklch(50% 0.12 70)` | `oklch(50% 0.12 70)` | internal | 1.0 | A dark amber of the cbox-ui hue, for warnings in the light mode, dark enough for text on the soft warning tone. |
| `--cms-ref-amber-900` | primitive | color | `#342814` | `#342814` | internal | 1.0 | The soft warning tone in the dark mode: --warning-soft of @cboxdk/cbox-ui over the page. |
| `--cms-color-surface` | semantic | color | `oklch(99.2% 0.003 247)` (`var(--cms-ref-gray-25)`) | `oklch(15.5% 0.01 250)` (`var(--cms-ref-gray-950)`) | stable | 1.0 | The background of the page and of controls. |
| `--cms-color-surface-raised` | semantic | color | `oklch(97.5% 0.008 250)` (`var(--cms-ref-gray-50)`) | `oklch(20% 0.01 250)` (`var(--cms-ref-gray-925)`) | stable | 1.0 | The background of a panel or a button that sits on the surface. |
| `--cms-color-card` | semantic | color | `#ffffff` (`var(--cms-ref-white)`) | `oklch(20% 0.01 250)` (`var(--cms-ref-gray-925)`) | stable | 1.0 | The background of a card or a panel that sits on the canvas, with a 1px border and the card shadow. |
| `--cms-color-canvas` | semantic | color | `oklch(97.5% 0.008 250)` (`var(--cms-ref-gray-50)`) | `oklch(18% 0.01 250)` (`var(--cms-ref-gray-940)`) | stable | 1.0 | The cool background behind cards, such as the product side of a sign-in page. |
| `--cms-color-muted` | semantic | color | `oklch(96% 0.006 250)` (`var(--cms-ref-gray-75)`) | `oklch(24% 0.012 250)` (`var(--cms-ref-gray-850)`) | experimental | 1.0 | A muted fill inside a card, such as a tile of facts. |
| `--cms-color-text` | semantic | color | `oklch(18% 0.012 250)` (`var(--cms-ref-gray-900)`) | `oklch(97% 0.005 247)` (`var(--cms-ref-gray-100)`) | stable | 1.0 | Body text and headings. |
| `--cms-color-text-muted` | semantic | color | `oklch(50% 0.012 250)` (`var(--cms-ref-gray-600)`) | `oklch(70% 0.012 250)` (`var(--cms-ref-gray-350)`) | stable | 1.0 | Secondary text, such as a field's hint or a page's description. |
| `--cms-color-border` | semantic | color | `oklch(92% 0.006 250)` (`var(--cms-ref-gray-200)`) | `oklch(28% 0.012 250)` (`var(--cms-ref-gray-800)`) | stable | 1.0 | Borders that only group content and carry no meaning of their own. |
| `--cms-color-border-strong` | semantic | color | `oklch(64% 0.012 250)` (`var(--cms-ref-gray-450)`) | `oklch(52% 0.014 250)` (`var(--cms-ref-gray-550)`) | stable | 1.0 | The border of a control, which shows where it is, so it meets the contrast of a user interface part. |
| `--cms-color-accent` | semantic | color | `oklch(45% 0.16 258)` (`var(--cms-ref-blue-600)`) | `oklch(65% 0.18 258)` (`var(--cms-ref-blue-400)`) | stable | 1.0 | The colour of links and of the primary action. |
| `--cms-color-accent-hover` | semantic | color | `oklch(41% 0.16 258)` (`var(--cms-ref-blue-700)`) | `oklch(72% 0.16 258)` (`var(--cms-ref-blue-300)`) | stable | 1.0 | The accent under the pointer. |
| `--cms-color-on-accent` | semantic | color | `oklch(99% 0.003 247)` (`var(--cms-ref-gray-10)`) | `oklch(16% 0.01 250)` (`var(--cms-ref-gray-1000)`) | stable | 1.0 | Text on the accent, such as the label of a primary button. |
| `--cms-color-danger` | semantic | color | `oklch(48% 0.19 25)` (`var(--cms-ref-red-600)`) | `oklch(68% 0.21 25)` (`var(--cms-ref-red-400)`) | stable | 1.0 | Errors and destructive actions. |
| `--cms-color-danger-soft` | semantic | color | `#f7e4e6` (`var(--cms-ref-red-50)`) | `#30181a` (`var(--cms-ref-red-900)`) | stable | 1.0 | The soft background of a danger message, under text in color-danger. |
| `--cms-color-focus` | semantic | color | `oklch(45% 0.16 258)` (`var(--cms-color-accent)`) | `oklch(65% 0.18 258)` (`var(--cms-color-accent)`) | stable | 1.0 | The colour of the focus ring. |
| `--cms-color-accent-subtle` | semantic | color | `#e9f0f9` (`var(--cms-ref-blue-50)`) | `#13243a` (`var(--cms-ref-blue-900)`) | stable | 1.0 | The background of a selected item, such as the current entry of the navigation. |
| `--cms-color-success` | semantic | color | `oklch(45% 0.13 145)` (`var(--cms-ref-green-600)`) | `oklch(74% 0.16 145)` (`var(--cms-ref-green-400)`) | stable | 1.0 | Success, such as a committed change. |
| `--cms-color-success-soft` | semantic | color | `#e4f2e8` (`var(--cms-ref-green-50)`) | `#172a1e` (`var(--cms-ref-green-900)`) | stable | 1.0 | The soft background of a success message, under text in color-success. |
| `--cms-color-warning` | semantic | color | `oklch(50% 0.12 70)` (`var(--cms-ref-amber-700)`) | `oklch(78% 0.16 70)` (`var(--cms-ref-amber-400)`) | stable | 1.0 | A warning, such as a change that waits for a projection. |
| `--cms-color-warning-soft` | semantic | color | `#f8eeda` (`var(--cms-ref-amber-50)`) | `#342814` (`var(--cms-ref-amber-900)`) | stable | 1.0 | The soft background of a warning message, under text in color-warning. |
| `--cms-color-backdrop` | semantic | color | `#000000` (`var(--cms-ref-black)`) | `#000000` (`var(--cms-ref-black)`) | stable | 1.0 | The colour of the backdrop behind a dialog, shown partly transparent. |
| `--cms-shadow-overlay` | semantic | shadow | `0 24px 48px -16px #ced2d9, 0 2px 6px #e3e7ec` (`0 24px 48px -16px var(--cms-ref-gray-300), 0 2px 6px var(--cms-ref-gray-150)`) | `0 24px 48px -16px #000000, 0 2px 6px #000000` (`0 24px 48px -16px var(--cms-ref-black), 0 2px 6px var(--cms-ref-black)`) | stable | 1.0 | The shadow of an overlay, such as a menu or a dialog. |
| `--cms-shadow-card` | semantic | shadow | `0 1px 1px #e3e7ec` (`0 1px 1px var(--cms-ref-gray-150)`) | `0 1px 1px #000000` (`0 1px 1px var(--cms-ref-black)`) | stable | 1.0 | The one-pixel shadow of a card, under its 1px border. |
| `--cms-font-family` | semantic | font-family | `'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif` | `'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif` | stable | 1.0 | The typeface of all text: Plus Jakarta Sans, self-hosted with the kit, as @cboxdk/cbox-ui sets it. |
| `--cms-font-family-display` | semantic | font-family | `'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif` (`var(--cms-font-family)`) | `'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif` (`var(--cms-font-family)`) | stable | 1.0 | The typeface of headings: Plus Jakarta Sans, as for all text. |
| `--cms-font-family-mono` | semantic | font-family | `'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, monospace` | `'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, monospace` | stable | 1.0 | The typeface of code, ids and numbers: JetBrains Mono, self-hosted with the kit, as @cboxdk/cbox-ui sets it. |
| `--cms-font-size-sm` | semantic | length | `0.8125rem` | `0.8125rem` | stable | 1.0 | Small text, such as a status code. |
| `--cms-font-size-md` | semantic | length | `0.875rem` | `0.875rem` | stable | 1.0 | The text of controls, labels and hints. |
| `--cms-font-size-lg` | semantic | length | `1rem` | `1rem` | stable | 1.0 | Body text and the value of an input. |
| `--cms-font-size-xl` | semantic | length | `1.375rem` | `1.375rem` | stable | 1.0 | A page's heading. |
| `--cms-font-size-display` | semantic | length | `1.875rem` | `1.875rem` | experimental | 1.0 | The heading of a page outside the panel's navigation, such as signing in. |
| `--cms-font-weight-regular` | semantic | number | `400` | `400` | stable | 1.0 | The weight of body text. |
| `--cms-font-weight-medium` | semantic | number | `500` | `500` | stable | 1.0 | The weight of labels, buttons and links. |
| `--cms-font-weight-semibold` | semantic | number | `600` | `600` | stable | 1.0 | The weight of headings. |
| `--cms-font-weight-bold` | semantic | number | `700` | `700` | experimental | 1.0 | The weight of display headings, such as the heading of a sign-in page. |
| `--cms-line-height` | semantic | number | `1.5` | `1.5` | stable | 1.0 | The line height of body text. |
| `--cms-line-height-tight` | semantic | number | `1.25` | `1.25` | stable | 1.0 | The line height of headings. |
| `--cms-space-1` | semantic | length | `0.25rem` | `0.25rem` | stable | 1.0 | The smallest space, such as between a label and its input. |
| `--cms-space-2` | semantic | length | `0.5rem` | `0.5rem` | stable | 1.0 | A small space, such as inside a button. |
| `--cms-space-3` | semantic | length | `0.75rem` | `0.75rem` | stable | 1.0 | A space between related items. |
| `--cms-space-4` | semantic | length | `1rem` | `1rem` | stable | 1.0 | The space between the fields of a form. |
| `--cms-space-6` | semantic | length | `1.5rem` | `1.5rem` | stable | 1.0 | The space between the parts of a panel. |
| `--cms-space-8` | semantic | length | `2rem` | `2rem` | stable | 1.0 | The largest space, such as the padding of a panel. |
| `--cms-radius-sm` | semantic | length | `6px` | `6px` | stable | 1.0 | The rounding of small parts, such as the focus ring of a link. |
| `--cms-radius-md` | semantic | length | `8px` | `8px` | stable | 1.0 | The rounding of controls. |
| `--cms-radius-lg` | semantic | length | `10px` | `10px` | stable | 1.0 | The rounding of panels. |
| `--cms-radius-xl` | semantic | length | `14px` | `14px` | experimental | 1.0 | The rounding of a card that stands on its own, such as the product card of a sign-in page. |
| `--cms-focus-ring` | semantic | shadow | `0 0 0 2px oklch(99.2% 0.003 247), 0 0 0 4px oklch(45% 0.16 258)` (`0 0 0 2px var(--cms-color-surface), 0 0 0 4px var(--cms-color-focus)`) | `0 0 0 2px oklch(15.5% 0.01 250), 0 0 0 4px oklch(65% 0.18 258)` (`0 0 0 2px var(--cms-color-surface), 0 0 0 4px var(--cms-color-focus)`) | stable | 1.0 | The focus ring of every control: a gap in the surface's colour and a ring in the focus colour. |
| `--cms-duration-fast` | semantic | duration | `150ms` | `150ms` | stable | 1.0 | Short transitions, such as a hover. It is 0 when the reader prefers reduced motion. |
| `--cms-target-size` | semantic | length | `1.5rem` | `1.5rem` | stable | 1.0 | The smallest height of a pointer target: at least 24 pixels (WCAG 2.2, 2.5.8). |
| `--cms-measure` | semantic | length | `32rem` | `32rem` | stable | 1.0 | The widest a panel of running text grows. |
| `--cms-button-radius` | component | length | `8px` (`var(--cms-radius-md)`) | `8px` (`var(--cms-radius-md)`) | experimental | 1.0 | The rounding of a button. |
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
| `--cms-color-text` | `--cms-color-surface` | text | 4.50:1 | 18.37:1 | 17.92:1 |
| `--cms-color-text` | `--cms-color-surface-raised` | text | 4.50:1 | 17.50:1 | 16.59:1 |
| `--cms-color-text-muted` | `--cms-color-surface` | text | 4.50:1 | 5.85:1 | 7.32:1 |
| `--cms-color-text-muted` | `--cms-color-surface-raised` | text | 4.50:1 | 5.57:1 | 6.78:1 |
| `--cms-color-accent` | `--cms-color-surface` | text | 4.50:1 | 7.44:1 | 5.95:1 |
| `--cms-color-accent` | `--cms-color-surface-raised` | text | 4.50:1 | 7.08:1 | 5.51:1 |
| `--cms-color-accent-hover` | `--cms-color-surface` | text | 4.50:1 | 8.82:1 | 7.71:1 |
| `--cms-color-accent-hover` | `--cms-color-surface-raised` | text | 4.50:1 | 8.39:1 | 7.14:1 |
| `--cms-color-on-accent` | `--cms-color-accent` | text | 4.50:1 | 7.39:1 | 5.90:1 |
| `--cms-color-on-accent` | `--cms-color-accent-hover` | text | 4.50:1 | 8.76:1 | 7.66:1 |
| `--cms-color-danger` | `--cms-color-surface` | text | 4.50:1 | 7.06:1 | 6.12:1 |
| `--cms-color-danger` | `--cms-color-surface-raised` | text | 4.50:1 | 6.73:1 | 5.67:1 |
| `--cms-color-border-strong` | `--cms-color-surface` | ui | 3.00:1 | 3.28:1 | 3.55:1 |
| `--cms-color-focus` | `--cms-color-surface` | ui | 3.00:1 | 7.44:1 | 5.95:1 |
| `--cms-color-focus` | `--cms-color-surface-raised` | ui | 3.00:1 | 7.08:1 | 5.51:1 |
| `--cms-color-success` | `--cms-color-surface` | text | 4.50:1 | 6.87:1 | 8.98:1 |
| `--cms-color-success` | `--cms-color-surface-raised` | text | 4.50:1 | 6.54:1 | 8.32:1 |
| `--cms-color-warning` | `--cms-color-surface` | text | 4.50:1 | 6.01:1 | 9.48:1 |
| `--cms-color-warning` | `--cms-color-surface-raised` | text | 4.50:1 | 5.72:1 | 8.78:1 |
| `--cms-color-accent` | `--cms-color-accent-subtle` | text | 4.50:1 | 6.63:1 | 4.76:1 |
| `--cms-color-text` | `--cms-color-accent-subtle` | text | 4.50:1 | 16.37:1 | 14.36:1 |
| `--cms-color-text-muted` | `--cms-color-accent-subtle` | text | 4.50:1 | 5.21:1 | 5.86:1 |
| `--cms-color-danger` | `--cms-color-danger-soft` | text | 4.50:1 | 5.92:1 | 5.18:1 |
| `--cms-color-text` | `--cms-color-danger-soft` | text | 4.50:1 | 15.39:1 | 15.15:1 |
| `--cms-color-success` | `--cms-color-success-soft` | text | 4.50:1 | 6.08:1 | 6.96:1 |
| `--cms-color-text` | `--cms-color-success-soft` | text | 4.50:1 | 16.25:1 | 13.89:1 |
| `--cms-color-warning` | `--cms-color-warning-soft` | text | 4.50:1 | 5.33:1 | 6.98:1 |
| `--cms-color-text` | `--cms-color-warning-soft` | text | 4.50:1 | 16.32:1 | 13.19:1 |
| `--cms-color-text` | `--cms-color-card` | text | 4.50:1 | 18.79:1 | 16.59:1 |
| `--cms-color-text-muted` | `--cms-color-card` | text | 4.50:1 | 5.99:1 | 6.78:1 |
| `--cms-color-accent` | `--cms-color-card` | text | 4.50:1 | 7.61:1 | 5.51:1 |
| `--cms-color-text` | `--cms-color-canvas` | text | 4.50:1 | 17.50:1 | 17.24:1 |
| `--cms-color-text-muted` | `--cms-color-canvas` | text | 4.50:1 | 5.57:1 | 7.04:1 |
| `--cms-color-accent` | `--cms-color-canvas` | text | 4.50:1 | 7.08:1 | 5.72:1 |
| `--cms-color-border-strong` | `--cms-color-card` | ui | 3.00:1 | 3.35:1 | 3.29:1 |
| `--cms-color-text-muted` | `--cms-color-danger-soft` | text | 4.50:1 | 4.90:1 | 6.19:1 |
| `--cms-color-accent` | `--cms-color-danger-soft` | text | 4.50:1 | 6.23:1 | 5.03:1 |
| `--cms-color-text` | `--cms-color-muted` | text | 4.50:1 | 16.74:1 | 15.07:1 |
| `--cms-color-text-muted` | `--cms-color-muted` | text | 4.50:1 | 5.33:1 | 6.16:1 |

## Part hooks

A part hook is a `data-cms-part` attribute on a curated element of the kit. The markup, the class names and every other attribute of a component are internal; only a theme, in the cascade layer `cms.theme`, may target a part hook, and only to set tokens for that part. Every hook is experimental.

| Part | Stability | Since | Element |
|---|---|---|---|
| `status-screen` | experimental | 1.0 | The panel of a status page, such as the page for an address the panel does not have. |
| `task-screen` | experimental | 1.0 | The panel of a page for one task outside the panel's navigation, such as signing in. |
