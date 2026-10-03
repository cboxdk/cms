<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests;

use Cbox\Cms\Panel\Boundary\ViteManifest;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Illuminate\Contracts\Container\Container;
use JsonException;
use RuntimeException;

/**
 * A panel build written for one test into a directory of its own below the system's temporary
 * directory: a Vite manifest and the files it names. The panel's own tests never depend on
 * `composer panel:build`, which gate 8 runs after gate 5, so they bind a build of this kind.
 */
final readonly class FixtureBuild
{
    /**
     * The entries of the panel's shared and refused modules, as the build names them
     * (ViteManifest::SHARED and REFUSED), each importing the chunk it re-exports.
     */
    public const array MODULES = [
        'cms-panel-module:react' => ['file' => 'assets/shared-react-0a0a0a.js', 'name' => 'shared-react', 'src' => 'cms-panel-module:react', 'isEntry' => true, 'imports' => ['_shared-4d5e6f.js']],
        'cms-panel-module:react/jsx-runtime' => ['file' => 'assets/shared-react-jsx-runtime-0b0b0b.js', 'name' => 'shared-react-jsx-runtime', 'src' => 'cms-panel-module:react/jsx-runtime', 'isEntry' => true, 'imports' => ['_shared-4d5e6f.js']],
        'cms-panel-module:react-dom' => ['file' => 'assets/shared-react-dom-0c0c0c.js', 'name' => 'shared-react-dom', 'src' => 'cms-panel-module:react-dom', 'isEntry' => true, 'imports' => ['_shared-4d5e6f.js']],
        'cms-panel-module:react-dom/client' => ['file' => 'assets/shared-react-dom-client-0d0d0d.js', 'name' => 'shared-react-dom-client', 'src' => 'cms-panel-module:react-dom/client', 'isEntry' => true, 'imports' => ['_shared-4d5e6f.js']],
        'cms-panel-module:@inertiajs/core' => ['file' => 'assets/refused-inertiajs-core-0e0e0e.js', 'name' => 'refused-inertiajs-core', 'src' => 'cms-panel-module:@inertiajs/core', 'isEntry' => true, 'imports' => ['_shared-4d5e6f.js']],
        'cms-panel-module:@inertiajs/react' => ['file' => 'assets/refused-inertiajs-react-0f0f0f.js', 'name' => 'refused-inertiajs-react', 'src' => 'cms-panel-module:@inertiajs/react', 'isEntry' => true, 'imports' => ['_shared-4d5e6f.js']],
    ];

    /** The manifest of a build with an entry that imports a shared chunk, lazily loads a page and has a font, and the module entries. */
    public const array MANIFEST = self::MODULES + [
        'src/app.tsx' => [
            'file' => 'assets/app-1a2b3c.js',
            'name' => 'app',
            'src' => 'src/app.tsx',
            'isEntry' => true,
            'imports' => ['_shared-4d5e6f.js'],
            'dynamicImports' => ['src/pages/Lazy.tsx'],
            'css' => ['assets/app-7a8b9c.css'],
            'assets' => ['assets/font-0a1b2c.woff2'],
        ],
        '_shared-4d5e6f.js' => [
            'file' => 'assets/shared-4d5e6f.js',
            'name' => 'shared',
            'css' => ['assets/shared-3c4d5e.css'],
        ],
        'src/pages/Lazy.tsx' => [
            'file' => 'assets/Lazy-9f8e7d.js',
            'name' => 'Lazy',
            'src' => 'src/pages/Lazy.tsx',
            'isDynamicEntry' => true,
            'imports' => ['_shared-4d5e6f.js'],
        ],
    ];

    /** The body of every JavaScript file of the build. */
    public const string SCRIPT = "export const panel = 'probe';\n";

    /** The body of every stylesheet of the build. */
    public const string STYLE = "body { margin: 0; }\n";

    private function __construct(public string $directory) {}

    /**
     * A manifest with the entries of the shared and refused modules added, which every build the
     * panel reads has.
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @return array<string, array<string, mixed>>
     */
    public static function withModules(array $manifest): array
    {
        $modules = self::MODULES;

        foreach ($modules as $key => $module) {
            $modules[$key]['imports'] = [];
        }

        return $modules + $manifest;
    }

    /**
     * Writes the manifest, as JSON or as the raw text given, and a file for every name in it that
     * is a file of a build; a name that would leave the directory, which a test plants to see it
     * refused, gets no file.
     *
     * @param  array<string, array<string, mixed>>|string  $manifest
     *
     * @throws JsonException
     */
    public static function write(array|string $manifest = self::MANIFEST): self
    {
        $directory = sys_get_temp_dir().'/cms-panel-build-'.bin2hex(random_bytes(6));
        self::makeDirectory($directory.'/.vite');
        file_put_contents($directory.'/'.ViteManifest::FILE, is_string($manifest) ? $manifest : json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        foreach (is_string($manifest) ? [] : $manifest as $chunk) {
            foreach ([$chunk['file'] ?? null, ...(array) ($chunk['css'] ?? []), ...(array) ($chunk['assets'] ?? [])] as $file) {
                if (! is_string($file) || ! PanelBuild::isFile($file)) {
                    continue;
                }

                self::makeDirectory(dirname($directory.'/'.$file));
                file_put_contents($directory.'/'.$file, match (pathinfo($file, PATHINFO_EXTENSION)) {
                    'js' => self::SCRIPT,
                    'css' => self::STYLE,
                    default => 'bytes',
                });
            }
        }

        return new self($directory);
    }

    public function read(): PanelBuild
    {
        return ViteManifest::read($this->directory);
    }

    /**
     * Binds this build as the panel's build in the application.
     */
    public function bind(Container $app): PanelBuild
    {
        $build = $this->read();
        $app->instance(PanelBuild::class, $build);

        return $build;
    }

    public function remove(): void
    {
        if (is_dir($this->directory)) {
            exec('rm -rf '.escapeshellarg($this->directory));
        }
    }

    private static function makeDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }
    }
}
