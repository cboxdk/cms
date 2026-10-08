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
