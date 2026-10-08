---
title: Cascade layers
weight: 38
description: "The cascade layers of the panel's stylesheets: their order, the rules every stylesheet of the kit keeps, how an addon's styles are scoped to its own part of the page, and the theme layer."
---

# Cascade layers

`js/ui-kit/src/layers.css` declares the order of the cascade layers once, and a page imports it before any other stylesheet. A later layer wins over an earlier one, whatever the specificity of the selectors:

1. `cms.reset`: the document styles of `base.css`.
2. `cms.tokens`: the design tokens of `tokens.css`.
3. `cms.addon`: the styles of an addon, each scoped to the addon's own part of the page, so an addon cannot restyle the kit or the panel.
4. `cms.kit`: the components of the kit.
5. `cms.panel`: the panel's own pages.
6. `cms.theme`: the themes the application selects, the only layer that may set tokens or target a part hook.

Every stylesheet of the kit keeps all its rules in its layer and uses no `!important`; `npm run test:kit -- layers` fails otherwise.

## An addon's styles

An addon's stylesheets sit in `cms.addon`, below the kit and the panel, so they cannot override the kit's components, the panel's pages or a theme. The SDK's Vite plugin puts every rule of an addon in `@layer cms.addon.<namespace>` and prefixes every selector with `[data-cms-addon="<namespace>"]`, the element the host renders each of the addon's contributions inside, so an addon styles its own markup and nothing else. The plugin fails the build on a rule outside the layer, on `!important`, on an `@import`, on a `--cms-*` declaration and on a selector on `.cms-*` or `[data-cms-part]`, and `cms:build` refuses a bundle whose stylesheet has a rule outside `@layer cms.addon` ([the bundle](../addons/panel/contributions.md#the-bundle)). An addon changes how the panel looks only through a theme.

## The theme layer

`cms.theme` is the last layer, and the only one that sets tokens on the panel or targets a curated part hook, `data-cms-part`. `cms:build` writes it from the themes the installation selects, and the panel links it on every page with the response's nonce. [Theme](../addons/panel/kinds/theme.md) says how an addon ships one.
