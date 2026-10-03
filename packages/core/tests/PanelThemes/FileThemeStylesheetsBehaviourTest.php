<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\PanelThemes;

use Cbox\Cms\Core\PanelThemes\Adapter\FileThemeStylesheets;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeStylesheets;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * ThemeStylesheetsBehaviour against theme.css in a scratch directory, the registry cache's
 * directory in an application; a directory that does not exist refuses every write.
 */
final class FileThemeStylesheetsBehaviourTest extends TestCase
{
    use ThemeStylesheetsBehaviour;

    private ?string $directory = null;

    #[Override]
    protected function tearDown(): void
    {
        if ($this->directory !== null && is_dir($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($this->directory);
        }

        $this->directory = null;

        parent::tearDown();
    }

    #[Override]
    protected function stylesheets(): ThemeStylesheets
    {
        return new FileThemeStylesheets($this->directory());
    }

    #[Override]
    protected function refuseWrites(): void
    {
        rmdir($this->directory());
    }

    private function directory(): string
    {
        if ($this->directory === null) {
            $this->directory = sys_get_temp_dir().'/cms-theme-css-'.bin2hex(random_bytes(6));
            mkdir($this->directory);
        }

        return $this->directory;
    }
}
