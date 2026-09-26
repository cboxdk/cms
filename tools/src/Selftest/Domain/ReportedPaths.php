<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Selftest\Domain;

/**
 * Finds where a tool's output names a file, and where that name leads on disk.
 *
 * Tools name files in different ways: relative to the working directory (Pint, Prettier,
 * Rector, PHPStan, tsc), absolute (ESLint), through a symlink in vendor/ (Pest's arch plugin
 * prints vendor/composer/../cboxdk/cms-core/...), JSON-escaped (Pint for agents) or with git's
 * a/ and b/ diff prefixes. Every name that ends in the file's base name is resolved against the
 * directory the tool ran in and followed through symlinks with realpath. The file is found when
 * one name leads to it, and no name leads to an existing file outside the directory, which would
 * mean the tool read another checkout.
 */
final readonly class ReportedPaths
{
    private const string PATH_CHARACTERS = 'A-Za-z0-9_.\-\/~@+';

    /**
     * @param  list<string>  $found  the real paths of the names that lead to the file
     * @param  list<string>  $outside  the real paths of the names that lead outside the directory
     */
    private function __construct(
        public array $found,
        public array $outside,
    ) {}

    /**
     * @param  string  $relativePath  the file, relative to the directory
     * @param  string  $directory  the real path of the directory the tool ran in
     */
    public static function search(string $output, string $relativePath, string $directory): self
    {
        $target = realpath($directory.'/'.$relativePath);
        $found = [];
        $outside = [];

        foreach (self::names(self::normalize($output), basename($relativePath)) as $name) {
            $resolved = self::resolve($name, $directory);

            if ($resolved === null) {
                continue;
            }

            if ($resolved === $target) {
                $found[] = $resolved;
            } elseif (! str_starts_with($resolved, $directory.'/')) {
                $outside[] = $resolved;
            }
        }

        return new self(array_values(array_unique($found)), array_values(array_unique($outside)));
    }

    public function isFound(): bool
    {
        return $this->found !== [] && $this->outside === [];
    }

    /**
     * Strips terminal colours and JSON's escaped slashes.
     */
    public static function normalize(string $output): string
    {
        return str_replace('\/', '/', (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $output));
    }

    /**
     * Every path-like name in the text that ends in the base name.
     *
     * @return list<string>
     */
    public static function names(string $text, string $baseName): array
    {
        $characters = self::PATH_CHARACTERS;
        $pattern = "/(?<![{$characters}])[{$characters}]*".preg_quote($baseName, '/').'(?![A-Za-z0-9_\-])/';

        preg_match_all($pattern, $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * The real path a name leads to from the directory, or null when it leads nowhere.
     */
    public static function resolve(string $name, string $directory): ?string
    {
        $attempts = [str_starts_with($name, '/') ? $name : $directory.'/'.$name];

        if (preg_match('#^[ab]/(.+)$#', $name, $match) === 1) {
            $attempts[] = $directory.'/'.$match[1];
        }

        foreach ($attempts as $attempt) {
            $real = realpath($attempt);

            if ($real !== false && is_file($real)) {
                return $real;
            }
        }

        return null;
    }
}
