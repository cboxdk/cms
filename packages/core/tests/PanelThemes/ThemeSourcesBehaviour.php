<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\PanelThemes;

use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenValue;
use Cbox\Cms\Core\PanelThemes\Domain\InvalidTheme;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeSources;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every implementation of ThemeSources does, run against FileThemeSources and the fake: it
 * gives the kit's token catalogue, reads a theme file and checks it against the catalogue, and
 * refuses a file it cannot read or that is not of a theme's form, with every reason.
 */
trait ThemeSourcesBehaviour
{
    /**
     * The sources, with the theme files given by name, each as the JSON it holds.
     *
     * @param  array<string, string>  $files  JSON by file name
     */
    abstract protected function sources(array $files): ThemeSources;

    /**
     * The path the sources read the file of the name from.
     */
    abstract protected function path(string $name): string;

    #[Test]
    public function it_gives_the_catalogue_of_the_kit(): void
    {
        $catalogue = $this->sources([])->catalogue();

        Assert::assertSame('{ref-blue-600}', $catalogue->token('color-accent')?->value->light);
        Assert::assertSame(['status-screen', 'task-screen'], $catalogue->parts);
        Assert::assertCount(39, $catalogue->contrast);
    }

    #[Test]
    public function it_reads_and_checks_a_theme_file(): void
    {
        $sources = $this->sources(['green.json' => ThemeWorld::GREEN]);
        $theme = $sources->theme(new ThemeName('brand:green'), $this->path('green.json'), $sources->catalogue());

        Assert::assertSame('brand:green', $theme->name->value);
        Assert::assertEquals(['color-accent' => new TokenValue('#1d6b47', '#7fd0a6'), 'radius-md' => TokenValue::both('4px')], $theme->tokens);
        Assert::assertEquals(['task-screen' => ['color-surface-raised' => new TokenValue('#f4f8f6', '#14201a')]], $theme->parts);
    }

    #[Test]
    public function it_refuses_a_file_it_cannot_read(): void
    {
        $sources = $this->sources([]);

        try {
            $sources->theme(ThemeName::app(), $this->path('missing.json'), $sources->catalogue());
            Assert::fail('A missing file was read.');
        } catch (InvalidTheme $invalid) {
            Assert::assertSame([sprintf('The file %s is not a readable local file.', $this->path('missing.json'))], $invalid->reasons);
        }
    }

    #[Test]
    public function it_refuses_a_file_that_is_not_a_theme_with_every_reason(): void
    {
        $sources = $this->sources(['wrong.json' => '{"logo":"x","tokens":{"ref-white":"#000000","radius-md":"red"}}']);

        try {
            $sources->theme(ThemeName::app(), $this->path('wrong.json'), $sources->catalogue());
            Assert::fail('A file that is no theme was read.');
        } catch (InvalidTheme $invalid) {
            Assert::assertCount(3, $invalid->reasons);
        }
    }
}
