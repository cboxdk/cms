---
title: Theme
weight: 31
description: "A theme: values for the kit's design tokens in a JSON file an addon ships, which the installation selects and orders and cms:build checks for contrast."
---

# Theme

A theme is data: values for the kit's semantic and component [design tokens](../../../ui/tokens.md) in a JSON file. It is not contributed to a point: an addon ships themes in its manifest, and the installation selects and orders the ones it wants. A theme nothing selects has no effect.

| | |
|---|---|
| Manifest | `PanelContributions(themes: ['<name>' => __DIR__.'/../resources/panel/theme.json'])`, with `AddonCapabilities(uiTheme: true)` |
| Installation | `cbox-cms.panel.themes`, such as `['app', 'fixtureaddon:brand']`, in the order they compose |
| Bundle | none |
| Test | `cms:panel:theme:check <file>`, and `cms:build` with the theme selected |

## Rules

- A theme has `tokens`, which set tokens on the whole panel, and `parts`, which set tokens on a curated part hook, such as `task-screen`. It never sets a primitive `ref-*` token, a name, a logo or a favicon.
- A value is one literal for both modes, or an object with exactly `light` and `dark`.
- `cms:build` composes the selected themes, a later one's value over an earlier one's, and refuses a composition that draws a contrast pair below WCAG 2.2 AA (`registry_panel_theme_contrast`) or a theme it cannot use (`registry_panel_theme_invalid`). It writes the result in the cascade layer `cms.theme`.

[Branding and theming the panel](../../../developers/panel-branding.md#themes) has the whole of it, and [ship a theme](../../../recipes/panel-theme.md) is the recipe.

## Example

The fixture addon's theme:

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
