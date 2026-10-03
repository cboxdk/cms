<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\PanelThemes\Fakes;

use Cbox\Cms\Core\PanelThemes\Domain\ThemeStylesheets;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Override;

/**
 * The theme's stylesheet in memory: the text of the last write, which read() gives back unless it
 * is empty, and the number of writes. refuseWrites() scripts the write failing as the file would.
 * ThemeStylesheetsBehaviour holds it to FileThemeStylesheets.
 */
final class FakeThemeStylesheets implements ThemeStylesheets
{
    public ?string $written = null;

    public int $writes = 0;

    private bool $refused = false;

    public function refuseWrites(): void
    {
        $this->refused = true;
    }

    #[Override]
    public function write(string $css): void
    {
        if ($this->refused) {
            throw RegistryCacheUnwritable::at('theme.css', 'the fake refuses writes');
        }

        $this->written = $css;
        $this->writes++;
    }

    #[Override]
    public function read(): ?string
    {
        return $this->written === null || $this->written === '' ? null : $this->written;
    }
}
