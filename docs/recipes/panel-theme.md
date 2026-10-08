---
title: Ship a theme
weight: 50
description: "Ship a theme of token values with an addon: the theme file, the capability and the manifest entry, the installation's selection, and the contrast checks cms:build runs."
---

# Ship a theme

A theme is data: values for the kit's semantic and component tokens in a JSON file. An addon ships it, and the installation decides whether to use it ([theme](../addons/panel/kinds/theme.md)). The fixture addon's theme `brand`, a magenta accent, was added this way.

## Inputs

- **namespace** and **name**: the addon's namespace and the theme's local name, such as `brand`, which the installation selects as `<namespace>:<name>`.
- **tokens**: the values to set on the whole panel and on curated part hooks, each for both modes or per mode.

## Files

| Path | What it holds |
|---|---|
| `resources/panel/theme.json`, in the addon's package | the theme, of the form of `js/ui-kit/src/generated/theme.v1.json`: `tokens` and `parts` |
| `src/<Addon>ServiceProvider.php`, in the addon's package | `AddonCapabilities(uiTheme: true)`, and the file in `PanelContributions::$themes` by its name |
| `config/cbox-cms.php`, in the application | `panel.themes`, the selected themes in the order they compose, such as `['fixtureaddon:brand']` |

## Steps

1. Write the theme with the token names of [the design tokens](../ui/tokens.md), without `--cms-`, and check it on its own with `vendor/bin/testbench cms:panel:theme:check resources/panel/theme.json`.
2. Add the capability and the theme to the manifest.
3. In the application, select the theme in `cbox-cms.panel.themes` and run `vendor/bin/testbench cms:build`, which writes `bootstrap/cache/cms/theme.css` in the layer `cms.theme`.

## Checks

- `cms:build` refuses a theme of an addon without `uiTheme`, a file not of the theme's form or a token that does not exist (`registry_panel_theme_invalid`), and a composition that draws a contrast pair below WCAG 2.2 AA in either mode (`registry_panel_theme_contrast`); it warns when two selected themes set one token (`registry_panel_theme_overlap`).
- A theme nothing selects is never read. `tests/Browser/Panel/PanelThemeTest.php` shows the panel keep its own tokens until the selection names the fixture addon's theme.

## Running example

The theme:

<!-- example-file: workbench/addons/fixtureaddon/resources/panel/theme.json -->
```json
{
  "tokens": {
    "color-accent": { "light": "#9d174d", "dark": "#f9a8d4" },
    "color-accent-hover": { "light": "#831843", "dark": "#fbcfe8" },
    "radius-md": "2px"
  },
  "parts": {
    "task-screen": {
      "color-surface-raised": { "light": "#fdf2f8", "dark": "#1f1219" }
    }
  }
}
```

The theme checked on its own, and composed once selected:

<!-- example: examples/Unit/Panel/Kinds/ThemeTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Kinds;

use Examples\Unit\Build\BuildTestCase;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * A theme is data: the workbench's fixture addon ships resources/panel/theme.json under the local
 * name brand, with the capability uiTheme, and the installation selects it as fixtureaddon:brand in
 * cbox-cms.panel.themes. cms:build checks the theme's form and the contrast pairs of the composed
 * themes, and writes theme.css in the cascade layer cms.theme; a theme nothing selects has no
 * effect. cms:panel:theme:check runs the same checks on one file.
 */
final class ThemeTest extends BuildTestCase
{
    private const string THEME = __DIR__.'/../../../../workbench/addons/fixtureaddon/resources/panel/theme.json';

    #[Test]
    public function it_writes_no_theme_until_the_installation_selects_one(): void
    {
        self::assertSame(0, $this->build());
        self::assertFileDoesNotExist($this->registryDirectory().'/theme.css');
    }

    #[Test]
    public function it_composes_the_selected_theme_into_the_theme_layer(): void
    {
        config()->set('cbox-cms.panel.themes', ['fixtureaddon:brand']);

        self::assertSame(0, $this->build());

        $css = file_get_contents($this->registryDirectory().'/theme.css');
        self::assertIsString($css);
        self::assertStringContainsString("@layer cms.theme {\n:root {\n--cms-color-accent: #9d174d;\n--cms-color-accent-hover: #831843;\n--cms-radius-md: 2px;\n}", $css);
        self::assertStringContainsString("[data-cms-part='task-screen'] {\n--cms-color-surface-raised: #fdf2f8;", $css);
    }

    #[Test]
    public function it_checks_one_theme_file_on_its_own(): void
    {
        self::assertSame(0, app(Kernel::class)->call('cms:panel:theme:check', ['theme' => self::THEME]));
    }
}
```
