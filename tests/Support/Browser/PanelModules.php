<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Browser;

use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Views\PanelRootView;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Node;
use FilesystemIterator;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The test-only modules of the Browser suite's panel tests (PRD 13.4, D2, D6), built from
 * tests/Browser/Fixtures/PanelModules with Vite as an addon's build will build an addon: the probe
 * host that stands in for the panel's addon runtime, the addon "acme" with a counter and a module
 * that imports @inertiajs/react, a module of React Aria Components and a module the host imports
 * from another origin.
 *
 * The build runs once per change of the sources or of package-lock.json, into
 * .cache/browser/panel-modules/<hash> of the checkout, and the test application serves the
 * modules below PATH, with the addon's modules below its PREFIX, as the panel will serve an
 * addon's files.
 */
final class PanelModules
{
    /** Where the test application serves the built modules. */
    public const string PATH = '/_probe/panel-modules/';

    /** The prefix of the addon acme's modules, the scope the import map gives it. */
    public const string PREFIX = self::PATH.'addons/acme/';

    /** The addon's modules, below its prefix. */
    public const array ADDON_FILES = ['counter.js', 'inertia.js'];

    private const string SOURCES = 'tests/Browser/Fixtures/PanelModules';

    private static ?string $directory = null;

    /**
     * The directory of the built modules, built first when the sources changed.
     */
    public static function directory(): string
    {
        return self::$directory ??= self::build();
    }

    /**
     * Serves the built modules below PATH, as JavaScript that any origin may import.
     */
    public static function serve(): void
    {
        $directory = self::directory();

        Route::get(self::PATH.'{path}', static function (string $path) use ($directory): Response {
            $file = $directory.'/'.$path;

            if (! is_file($file)) {
                return new Response('', 404);
            }

            return new Response((string) file_get_contents($file), 200, [
                'Content-Type' => 'text/javascript; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Access-Control-Allow-Origin' => '*',
            ]);
        })->where('path', '[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.js');
    }

    /**
     * The import map of a panel page of the real build with the scope of the addon acme and the
     * integrity of its files, or, given, the integrity the test names for one of them instead.
     *
     * @param  array<string, string>  $integrity  integrity by file of the addon
     */
    public static function importMap(array $integrity = []): ImportMap
    {
        $files = [];

        foreach (self::ADDON_FILES as $file) {
            $files[self::PREFIX.$file] = $integrity[$file] ?? self::integrity('addons/acme/'.$file);
        }

        return PanelRootView::importMap(app(PanelBuild::class), app(UrlGenerator::class))->withAddonScope(self::PREFIX, $files);
    }

    /**
     * The SHA-384 of a built module, as an import map's integrity names it.
     */
    public static function integrity(string $file): string
    {
        return 'sha384-'.base64_encode(hash('sha384', (string) file_get_contents(self::directory().'/'.$file), true));
    }

    private static function build(): string
    {
        $root = Codebase::root();
        $sources = $root.'/'.self::SOURCES;
        $hash = hash_init('sha256');
        hash_update_file($hash, $root.'/package-lock.json');

        foreach (self::sources($sources) as $file) {
            hash_update($hash, substr($file, strlen($sources)));
            hash_update_file($hash, $file);
        }

        $directory = $root.'/.cache/browser/panel-modules/'.hash_final($hash);

        if (is_file($directory.'/host.js')) {
            return $directory;
        }

        $staging = $directory.'.'.getmypid().'.'.bin2hex(random_bytes(4));
        $process = Node::tool('vite', ['build', '--config', self::SOURCES.'/vite.config.ts', '--outDir', $staging]);

        if (! $process->isSuccessful() || ! is_file($staging.'/host.js')) {
            throw new RuntimeException("Vite could not build the panel's test modules: {$process->getErrorOutput()}{$process->getOutput()}");
        }

        // Another process may have built the same sources meanwhile; either build will do.
        if (! @rename($staging, $directory) && ! is_file($directory.'/host.js')) {
            throw new RuntimeException("Cannot move the panel's test modules to {$directory}.");
        }

        return $directory;
    }

    /**
     * @return list<string>
     */
    private static function sources(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
