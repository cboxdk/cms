<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Domain\PanelBuildUnavailable;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SplFileObject;

/**
 * Reads the panel's build from the manifest Vite writes next to it, `.vite/manifest.json` (PRD
 * 13.4): an object from each source to its chunk, with the chunk's `file`, whether it `isEntry`,
 * the keys of the chunks it `imports` at once and lazily (`dynamicImports`), and its `css` and
 * `assets`. The entry is the chunk of ENTRY. Its styles are its own and those of every chunk it
 * imports at once, depth first, each once; its preloads are those chunks' files. The files the
 * build serves are every chunk's file, stylesheets and assets.
 *
 * The build also has an entry for each of the panel's shared modules, React's and the SDK's
 * subpaths, and for each module an addon may not import, named in js/panel/shared-modules.json
 * and in SHARED, SDK and REFUSED here (the panel's tests hold the two equal), and found by the
 * `name` of their entry chunk. Every script of the build, a `.js` file, gets the SHA-384 of its
 * bytes, for the import map's integrity.
 *
 * It reads a local file and never a URL: the directory must be an absolute path, and one that
 * names a stream wrapper is refused before any file function sees it. The Arch suite allows
 * SplFileObject here because of that (Egress).
 */
#[Internal]
final readonly class ViteManifest
{
    /** The panel's entry, as js/panel/vite.config.ts names it. */
    public const string ENTRY = 'src/app.tsx';

    /** The manifest, relative to the build's directory. */
    public const string FILE = '.vite/manifest.json';

    /**
     * The panel's shared modules, by specifier, with the name of their entry chunk, as
     * js/panel/shared-modules.json lists them under `shared`.
     *
     * @var array<string, string>
     */
    public const array SHARED = [
        'react' => 'shared-react',
        'react/jsx-runtime' => 'shared-react-jsx-runtime',
        'react-dom' => 'shared-react-dom',
        'react-dom/client' => 'shared-react-dom-client',
    ];

    /**
     * The SDK's subpaths the panel shares, by specifier, with the name of their entry chunk, as
     * js/panel/shared-modules.json lists them under `sdk`: ES modules, re-exported as they are.
     *
     * @var array<string, string>
     */
    public const array SDK = [
        '@cboxdk/cms-panel/experimental' => 'shared-cboxdk-cms-panel-experimental',
        '@cboxdk/cms-panel/extend' => 'shared-cboxdk-cms-panel-extend',
        '@cboxdk/cms-panel/ui' => 'shared-cboxdk-cms-panel-ui',
    ];

    /**
     * The modules an addon may not import, by specifier, with the name of their entry chunk, as
     * js/panel/shared-modules.json lists them under `refused`.
     *
     * @var array<string, string>
     */
    public const array REFUSED = [
        '@cboxdk/cms-ui-kit' => 'refused-cboxdk-cms-ui-kit',
        '@inertiajs/core' => 'refused-inertiajs-core',
        '@inertiajs/react' => 'refused-inertiajs-react',
        'react-aria-components' => 'refused-react-aria-components',
    ];

    /**
     * @throws PanelBuildUnavailable when the manifest is missing, unreadable or not the panel's
     */
    public static function read(string $directory): PanelBuild
    {
        $manifest = rtrim($directory, '/').'/'.self::FILE;

        if (! str_starts_with($directory, '/') || LocalPath::namesStreamWrapper($directory)) {
            throw PanelBuildUnavailable::malformed($manifest, 'the build directory is not an absolute local path.');
        }

        $json = self::contents($manifest, $manifest);

        try {
            $chunks = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw PanelBuildUnavailable::malformed($manifest, 'it is not JSON.', $invalid);
        }

        if (! is_array($chunks) || array_is_list($chunks)) {
            throw PanelBuildUnavailable::malformed($manifest, 'it is not an object of chunks.');
        }

        $entry = self::chunk($manifest, $chunks, self::ENTRY);

        if (($entry['isEntry'] ?? false) !== true) {
            throw PanelBuildUnavailable::malformed($manifest, 'it has no entry chunk for '.self::ENTRY.'.');
        }

        $styles = [];
        $preloads = [];
        self::collect($manifest, $chunks, self::ENTRY, $styles, $preloads, []);
        $files = [];

        foreach (array_keys($chunks) as $key) {
            $chunk = self::chunk($manifest, $chunks, (string) $key);

            foreach ([self::string($manifest, $chunk, 'file'), ...self::strings($manifest, $chunk, 'css'), ...self::strings($manifest, $chunk, 'assets')] as $file) {
                $files[$file] = $file;
            }
        }

        sort($files, SORT_STRING);
        $integrity = [];

        foreach ($files as $file) {
            // Checked before any file is read, so no name in the manifest reaches outside the build.
            if (! PanelBuild::isFile($file)) {
                throw PanelBuildUnavailable::malformed($manifest, "The panel's build names {$file}, which is not a relative path inside the build.");
            }

            if (str_ends_with($file, '.js')) {
                $integrity[$file] = 'sha384-'.base64_encode(hash('sha384', self::contents(rtrim($directory, '/').'/'.$file, $manifest), true));
            }
        }

        try {
            return new PanelBuild(
                rtrim($directory, '/'),
                self::string($manifest, $entry, 'file'),
                array_values($styles),
                array_values(array_diff($preloads, [self::string($manifest, $entry, 'file')])),
                $files,
                hash('sha256', $json),
                self::entries($manifest, $chunks, [...self::SHARED, ...self::SDK]),
                self::entries($manifest, $chunks, self::REFUSED),
                $integrity,
            );
        } catch (InvalidArgumentException $invalid) {
            throw PanelBuildUnavailable::malformed($manifest, $invalid->getMessage(), $invalid);
        }
    }

    /**
     * Adds the stylesheets and the file of a chunk and of every chunk it imports at once, depth
     * first and each once.
     *
     * @param  array<mixed>  $chunks
     * @param  array<string, string>  $styles
     * @param  array<string, string>  $preloads
     * @param  array<string, true>  $seen
     */
    private static function collect(string $manifest, array $chunks, string $key, array &$styles, array &$preloads, array $seen): void
    {
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $chunk = self::chunk($manifest, $chunks, $key);

        foreach (self::strings($manifest, $chunk, 'imports') as $import) {
            self::collect($manifest, $chunks, $import, $styles, $preloads, $seen);
        }

        foreach (self::strings($manifest, $chunk, 'css') as $style) {
            $styles[$style] = $style;
        }

        $file = self::string($manifest, $chunk, 'file');
        $preloads[$file] = $file;
    }

    /**
     * @param  array<mixed>  $chunks
     * @return array<mixed>
     */
    private static function chunk(string $manifest, array $chunks, string $key): array
    {
        $chunk = $chunks[$key] ?? null;

        if (! is_array($chunk) || array_is_list($chunk)) {
            throw PanelBuildUnavailable::malformed($manifest, "it has no chunk {$key}.");
        }

        return $chunk;
    }

    /**
     * @param  array<mixed>  $chunk
     */
    private static function string(string $manifest, array $chunk, string $member): string
    {
        $value = $chunk[$member] ?? null;

        return is_string($value) && $value !== '' ? $value : throw PanelBuildUnavailable::malformed($manifest, "a chunk has no {$member}.");
    }

    /**
     * @param  array<mixed>  $chunk
     * @return list<string>
     */
    private static function strings(string $manifest, array $chunk, string $member): array
    {
        $values = $chunk[$member] ?? [];

        if (! is_array($values) || ! array_is_list($values)) {
            throw PanelBuildUnavailable::malformed($manifest, "the {$member} of a chunk are not a list.");
        }

        $strings = [];

        foreach ($values as $value) {
            $strings[] = is_string($value) && $value !== '' ? $value : throw PanelBuildUnavailable::malformed($manifest, "the {$member} of a chunk are not names.");
        }

        return $strings;
    }

    /**
     * The file of the entry chunk of each module, by specifier.
     *
     * @param  array<mixed>  $chunks
     * @param  array<string, string>  $modules  the name of each module's entry chunk, by specifier
     * @return array<string, string>
     */
    private static function entries(string $manifest, array $chunks, array $modules): array
    {
        $entries = [];

        foreach ($modules as $specifier => $name) {
            foreach ($chunks as $chunk) {
                if (is_array($chunk) && ($chunk['name'] ?? null) === $name && ($chunk['isEntry'] ?? false) === true) {
                    $entries[$specifier] = self::string($manifest, $chunk, 'file');

                    continue 2;
                }
            }

            throw PanelBuildUnavailable::malformed($manifest, "it has no entry chunk {$name} for the module {$specifier}.");
        }

        return $entries;
    }

    /**
     * The bytes of a file of the build: the manifest, or a file it names.
     */
    private static function contents(string $path, string $manifest): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw $path === $manifest ? PanelBuildUnavailable::missing($manifest) : PanelBuildUnavailable::malformed($manifest, "it names {$path}, which does not exist or cannot be read.");
        }

        try {
            $file = new SplFileObject($path, 'rb');
            $size = $file->getSize();
            $contents = $size === 0 || $size === false ? '' : $file->fread($size);
        } catch (RuntimeException $failed) {
            throw PanelBuildUnavailable::malformed($manifest, $path === $manifest ? 'it cannot be read.' : "{$path} cannot be read.", $failed);
        }

        if ($path !== $manifest) {
            return is_string($contents) ? $contents : throw PanelBuildUnavailable::malformed($manifest, "{$path} cannot be read.");
        }

        return is_string($contents) && $contents !== '' ? $contents : throw PanelBuildUnavailable::malformed($manifest, 'it is empty.');
    }
}
