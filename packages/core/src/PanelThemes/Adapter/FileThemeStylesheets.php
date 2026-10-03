<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeStylesheets;
use Cbox\Cms\Core\Registry\Boundary\LocalFiles;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;

/**
 * The stylesheet of the composed theme as the file FILE in the registry cache's directory,
 * bootstrap/cache/cms/theme.css in an application (PRD 13.4); with no theme selected there is no
 * file, and write('') removes one an earlier build left. write() writes a temporary file next
 * to it and renames it into place, so the panel reads the old or the new stylesheet, never half of
 * one; the registry cache keeps FILE and its temporary file when it removes the files it does not
 * write. It refuses a directory that names a stream wrapper before it touches it (GUARDRAILS 3).
 */
#[Internal]
final readonly class FileThemeStylesheets implements ThemeStylesheets
{
    /** The stylesheet's file in the directory. */
    public const string FILE = 'theme.css';

    public function __construct(private string $directory) {}

    public function write(string $css): void
    {
        $path = $this->directory.'/'.self::FILE;

        if (LocalPath::namesStreamWrapper($this->directory)) {
            throw RegistryCacheUnwritable::streamWrapper($this->directory);
        }

        if ($css === '') {
            $failure = $this->attempt(static fn (): bool => ! is_file($path) || unlink($path), 'the file could not be removed');

            if ($failure !== null) {
                throw RegistryCacheUnwritable::removing($path, $failure);
            }

            return;
        }

        $temporary = sprintf('%s.%s.tmp', $path, bin2hex(random_bytes(8)));
        $failure = $this->attempt(static fn (): bool => file_put_contents($temporary, $css) === strlen($css), 'the file could not be written')
            ?? $this->attempt(static fn (): bool => rename($temporary, $path), 'the file could not be moved into place');

        if ($failure !== null) {
            $this->attempt(static fn (): bool => ! is_file($temporary) || unlink($temporary), 'the temporary file could not be removed');

            throw RegistryCacheUnwritable::at($path, $failure);
        }
    }

    public function read(): ?string
    {
        $css = LocalFiles::read($this->directory.'/'.self::FILE);

        return $css === null || $css === '' ? null : $css;
    }

    /**
     * Runs the file operation and gives the warning PHP raised when it failed, or null when it
     * succeeded.
     *
     * @param  callable(): bool  $operation
     */
    private function attempt(callable $operation, string $fallback): ?string
    {
        $warning = null;

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $succeeded = $operation();
        } finally {
            restore_error_handler();
        }

        return $succeeded ? null : ($warning ?? $fallback);
    }
}
