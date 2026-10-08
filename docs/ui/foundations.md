---
title: Foundations
weight: 37
description: "The foundations of the component kit: the Cbox design language, the typefaces, the light and dark modes, motion, target size and the three tiers of design tokens."
---

# Foundations

## Design language

The kit follows the Cbox design language of `@cboxdk/cbox-ui` (MIT), a dependency of `js/ui-kit`: its palette in `tokens/cbox.css`, refined and minimal, with a deep blue primary, a cool canvas, white cards with a 1px border and a soft shadow, and soft tones for messages. The kit's tokens take their values from that file; where a pair of the kit's contrast checks needs more contrast than a cbox-ui value gives, such as the text of a tone or the border of a control, the kit uses the same hue at another lightness, and each primitive's description in [Design tokens](tokens.md) says which. `npm run test:kit -- brand` holds the copied values to the installed `tokens/cbox.css`.

Text and headings are set in Plus Jakarta Sans and code, ids and numbers in JetBrains Mono, both variable fonts under the SIL Open Font License 1.1 from `@fontsource-variable/plus-jakarta-sans` and `@fontsource-variable/jetbrains-mono`. `base.css` declares their Latin and Latin Extended faces, and the panel's build bundles the files, so they come from the panel's own origin. A system face is only the fallback after them.

A message, a card or a panel never marks its tone with a coloured stripe at its edge: a message shows its tone with its soft background, its icon and its text, and says what to do next. The same test fails on a `border-left`, a `border-inline-start` or an inset shadow at that edge in a tone in any stylesheet of the kit or the panel.

## Colour modes

Every colour token has a value for the light and the dark mode. The panel follows the system's preference, `prefers-color-scheme`, unless the root element names a mode with `data-theme="light"` or `data-theme="dark"`. The kit's stories show every component in both, and in forced colours, where the system's own colours replace the tokens.

## Motion

The kit's transitions take their length from the `--cms-duration-*` tokens. When the reader prefers reduced motion, `prefers-reduced-motion: reduce`, every duration is 0, so nothing moves.

## Target size

`--cms-target-size` is the least size of a pointer target, 24 pixels (WCAG 2.2, 2.5.8), and the kit's buttons, icon buttons and controls keep to it. A theme cannot set it lower: `cms:build` refuses a composition that does.

## Tokens

Every value of the kit is a design token, a custom property `--cms-*` written from one source, `js/ui-kit/tokens.json`, in three tiers:

- **Primitive** tokens, `--cms-ref-*`, are the palette. Only the kit sets them, and no component reads one.
- **Semantic** tokens, such as `--cms-color-surface` and `--cms-space-4`, say what a value is for. Components read them, and a theme may set them.
- **Component** tokens, such as `--cms-button-radius`, belong to one component. They are experimental, and a theme may set them.

[Design tokens](tokens.md) lists every token with its values, the contrast pairs and the part hooks, and [Branding and theming the panel](../developers/panel-branding.md#themes) says how an installation sets them.
