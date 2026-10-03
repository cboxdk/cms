<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\PanelThemes\Boundary\ThemeDocument;
use Cbox\Cms\Core\PanelThemes\Boundary\TokenCatalogueJson;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\Theme;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenCatalogue;
use Cbox\Cms\Core\PanelThemes\Domain\InvalidTheme;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeSources;
use Cbox\Cms\Core\PanelThemes\Domain\TokenCatalogueUnavailable;
use Cbox\Cms\Core\Registry\Boundary\LocalFiles;

/**
 * Reads the token catalogue from CATALOGUE below the root of the installed cboxdk/cms, and a
 * theme from the local file its owner names, through LocalFiles, which refuses a path that names a
 * stream wrapper. ThemeDocument checks a theme against the catalogue.
 */
#[Internal]
final readonly class FileThemeSources implements ThemeSources
{
    /** The kit's token catalogue, relative to the root of cboxdk/cms. */
    public const string CATALOGUE = 'js/ui-kit/tokens.json';

    /**
     * @param  string|null  $root  the root of cboxdk/cms, or null for the one this file is in
     */
    public function __construct(private ?string $root = null) {}

    public function catalogue(): TokenCatalogue
    {
        $path = ($this->root ?? dirname(__DIR__, 5)).'/'.self::CATALOGUE;
        $json = LocalFiles::read($path);

        if ($json === null) {
            throw new TokenCatalogueUnavailable(sprintf('The token catalogue %s cannot be read. Install cboxdk/cms again; the catalogue is part of the package.', $path));
        }

        return TokenCatalogueJson::decode($json);
    }

    public function theme(ThemeName $name, string $file, TokenCatalogue $catalogue): Theme
    {
        $json = LocalFiles::read($file);

        if ($json === null) {
            throw new InvalidTheme([sprintf('The file %s is not a readable local file.', $file)]);
        }

        return ThemeDocument::read($name, $json, $catalogue);
    }
}
