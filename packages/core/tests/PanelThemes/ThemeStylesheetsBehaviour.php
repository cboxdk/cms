<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\PanelThemes;

use Cbox\Cms\Core\PanelThemes\Domain\ThemeStylesheets;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every implementation of ThemeStylesheets does, run against FileThemeStylesheets and the
 * fake: it gives back the stylesheet written last, none before the first write or after the empty
 * one, and a write that fails throws RegistryCacheUnwritable.
 */
trait ThemeStylesheetsBehaviour
{
    abstract protected function stylesheets(): ThemeStylesheets;

    /**
     * Makes every later write fail.
     */
    abstract protected function refuseWrites(): void;

    #[Test]
    public function it_has_no_stylesheet_before_the_first_write(): void
    {
        Assert::assertNull($this->stylesheets()->read());
    }

    #[Test]
    public function it_gives_back_the_stylesheet_written_last(): void
    {
        $stylesheets = $this->stylesheets();
        $stylesheets->write("@layer cms.theme {\n:root {\n--cms-radius-md: 4px;\n}\n}\n");
        $stylesheets->write("@layer cms.theme {\n:root {\n--cms-radius-md: 8px;\n}\n}\n");

        Assert::assertSame("@layer cms.theme {\n:root {\n--cms-radius-md: 8px;\n}\n}\n", $stylesheets->read());
    }

    #[Test]
    public function the_empty_stylesheet_is_none(): void
    {
        $stylesheets = $this->stylesheets();
        $stylesheets->write("@layer cms.theme {\n}\n");
        $stylesheets->write('');

        Assert::assertNull($stylesheets->read());
    }

    #[Test]
    public function a_write_that_fails_throws(): void
    {
        $this->refuseWrites();

        $this->expectException(RegistryCacheUnwritable::class);

        $this->stylesheets()->write('@layer cms.theme {}');
    }
}
