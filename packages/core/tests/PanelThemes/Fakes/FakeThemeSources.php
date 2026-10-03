<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\PanelThemes\Fakes;

use Cbox\Cms\Core\PanelThemes\Boundary\ThemeDocument;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\Theme;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenCatalogue;
use Cbox\Cms\Core\PanelThemes\Domain\InvalidTheme;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeSources;
use Cbox\Cms\Core\PanelThemes\Domain\TokenCatalogueUnavailable;
use Override;

/**
 * The token catalogue and the theme files in memory: the catalogue a test gives it, or none, which
 * is TokenCatalogueUnavailable, and the JSON of each theme file by its path, checked with
 * ThemeDocument as FileThemeSources checks a file it reads. A path it does not hold is not a
 * readable file. It records each path it is asked for. ThemeSourcesBehaviour holds it to
 * FileThemeSources.
 */
final class FakeThemeSources implements ThemeSources
{
    /** @var list<string> the paths of the themes read, in order */
    public array $read = [];

    /**
     * @param  array<string, string>  $files  JSON by path
     */
    public function __construct(
        private readonly ?TokenCatalogue $catalogue = null,
        private readonly array $files = [],
    ) {}

    #[Override]
    public function catalogue(): TokenCatalogue
    {
        return $this->catalogue ?? throw new TokenCatalogueUnavailable('The fake holds no token catalogue.');
    }

    #[Override]
    public function theme(ThemeName $name, string $file, TokenCatalogue $catalogue): Theme
    {
        $this->read[] = $file;
        $json = $this->files[$file] ?? null;

        if ($json === null) {
            throw new InvalidTheme([sprintf('The file %s is not a readable local file.', $file)]);
        }

        return ThemeDocument::read($name, $json, $catalogue);
    }
}
