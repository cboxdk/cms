<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\PanelThemes;

use Cbox\Cms\Core\PanelThemes\Adapter\FileThemeSources;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeSources;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * ThemeSourcesBehaviour against the files: the catalogue js/ui-kit/tokens.json of this checkout,
 * and theme files in a scratch directory.
 */
final class FileThemeSourcesBehaviourTest extends TestCase
{
    use ThemeSourcesBehaviour;

    private ?string $directory = null;

    #[Override]
    protected function tearDown(): void
    {
        foreach (glob($this->directory().'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory());
        $this->directory = null;

        parent::tearDown();
    }

    #[Override]
    protected function sources(array $files): ThemeSources
    {
        foreach ($files as $name => $json) {
            file_put_contents($this->path($name), $json);
        }

        return new FileThemeSources;
    }

    #[Override]
    protected function path(string $name): string
    {
        return $this->directory().'/'.$name;
    }

    private function directory(): string
    {
        if ($this->directory === null) {
            $this->directory = sys_get_temp_dir().'/cms-themes-'.bin2hex(random_bytes(6));
            mkdir($this->directory);
        }

        return $this->directory;
    }
}
