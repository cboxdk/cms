<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Boundary;

use Cbox\Cms\Panel\Boundary\ViteManifest;
use Cbox\Cms\Panel\Domain\PanelBuildUnavailable;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Tests\Support\Arch\Codebase;

/*
 * The panel's build as Vite's manifest describes it (PRD 13.4): the entry, the stylesheets and
 * module preloads the root view names, every file the asset route may serve, and a version per
 * build. A missing or broken manifest is a clear error that says how to build the panel.
 */

it('reads the entry, its stylesheets and preloads, every file and the version from the manifest', function (): void {
    $fixture = FixtureBuild::write();

    try {
        $build = $fixture->read();

        expect($build->directory)->toBe($fixture->directory)
            ->and($build->entry)->toBe('assets/app-1a2b3c.js')
            ->and($build->styles)->toBe(['assets/shared-3c4d5e.css', 'assets/app-7a8b9c.css'])
            ->and($build->preloads)->toBe(['assets/shared-4d5e6f.js'])
            ->and($build->files)->toBe([
                'assets/Lazy-9f8e7d.js',
                'assets/app-1a2b3c.js',
                'assets/app-7a8b9c.css',
                'assets/font-0a1b2c.woff2',
                'assets/refused-inertiajs-core-0e0e0e.js',
                'assets/refused-inertiajs-react-0f0f0f.js',
                'assets/shared-3c4d5e.css',
                'assets/shared-4d5e6f.js',
                'assets/shared-react-0a0a0a.js',
                'assets/shared-react-dom-0c0c0c.js',
                'assets/shared-react-dom-client-0d0d0d.js',
                'assets/shared-react-jsx-runtime-0b0b0b.js',
            ])
            ->and($build->version)->toBe(hash('sha256', (string) file_get_contents($fixture->directory.'/'.ViteManifest::FILE)));
    } finally {
        $fixture->remove();
    }
});

it('gives each build its own version', function (): void {
    $first = FixtureBuild::write();
    $manifest = FixtureBuild::MANIFEST;
    $manifest['src/app.tsx']['file'] = 'assets/app-ffffff.js';
    $second = FixtureBuild::write($manifest);

    try {
        expect($first->read()->version)->not->toBe($second->read()->version);
    } finally {
        $first->remove();
        $second->remove();
    }
});

it('says how to build the panel when there is no manifest', function (): void {
    $directory = sys_get_temp_dir().'/cms-panel-unbuilt-'.bin2hex(random_bytes(6));

    expect(fn (): mixed => ViteManifest::read($directory))
        ->toThrow(PanelBuildUnavailable::class, "The panel is not built: {$directory}/.vite/manifest.json does not exist or cannot be read. Build it with `composer panel:build`.");
});

it('refuses a manifest that is not the panel\'s', function (array|string $manifest, string $reason): void {
    /** @var array<string, array<string, mixed>>|string $manifest */
    $fixture = FixtureBuild::write($manifest);

    try {
        expect($fixture->read(...))->toThrow(PanelBuildUnavailable::class, $reason);
    } finally {
        $fixture->remove();
    }
})->with([
    'not JSON' => ['{', 'it is not JSON.'],
    'a list' => ['[]', 'it is not an object of chunks.'],
    'empty' => ['', 'it is empty.'],
    'no entry for the panel' => [['src/other.tsx' => ['file' => 'assets/other.js', 'isEntry' => true]], 'it has no chunk src/app.tsx.'],
    'the entry not marked as one' => [['src/app.tsx' => ['file' => 'assets/app.js']], 'it has no entry chunk for src/app.tsx.'],
    'a chunk without a file' => [['src/app.tsx' => ['isEntry' => true]], 'a chunk has no file.'],
    'an import of a missing chunk' => [['src/app.tsx' => ['file' => 'assets/app.js', 'isEntry' => true, 'imports' => ['_gone.js']]], 'it has no chunk _gone.js.'],
    'stylesheets that are no list' => [['src/app.tsx' => ['file' => 'assets/app.js', 'isEntry' => true, 'css' => 'assets/app.css']], 'the css of a chunk are not a list.'],
    'a file outside the build' => [['src/app.tsx' => ['file' => '../../composer.json', 'isEntry' => true]], 'which is not a relative path inside the build'],
]);

it('refuses a build directory that is relative or names a stream wrapper before it reads anything', function (string $directory): void {
    expect(fn (): mixed => ViteManifest::read($directory))->toThrow(PanelBuildUnavailable::class, 'the build directory is not an absolute local path.');
})->with([
    'relative' => ['packages/panel/dist'],
    'a URL' => ['http://127.0.0.1/dist'],
    'a phar' => ['phar:///tmp/panel.phar/dist'],
]);

it('follows an import cycle without looping and names each chunk once', function (): void {
    $fixture = FixtureBuild::write(FixtureBuild::withModules([
        'src/app.tsx' => ['file' => 'assets/app.js', 'isEntry' => true, 'imports' => ['_a.js']],
        '_a.js' => ['file' => 'assets/a.js', 'imports' => ['_b.js'], 'css' => ['assets/a.css']],
        '_b.js' => ['file' => 'assets/b.js', 'imports' => ['_a.js', 'src/app.tsx'], 'css' => ['assets/a.css']],
    ]));

    try {
        $build = $fixture->read();

        expect($build->preloads)->toBe(['assets/b.js', 'assets/a.js'])
            ->and($build->styles)->toBe(['assets/a.css']);
    } finally {
        $fixture->remove();
    }
});

it('reads the entry of each shared and refused module by its name, and the SHA-384 of every script', function (): void {
    $fixture = FixtureBuild::write();

    try {
        $build = $fixture->read();
        $integrity = 'sha384-'.base64_encode(hash('sha384', FixtureBuild::SCRIPT, true));

        expect($build->shared)->toBe([
            'react' => 'assets/shared-react-0a0a0a.js',
            'react/jsx-runtime' => 'assets/shared-react-jsx-runtime-0b0b0b.js',
            'react-dom' => 'assets/shared-react-dom-0c0c0c.js',
            'react-dom/client' => 'assets/shared-react-dom-client-0d0d0d.js',
        ])
            ->and($build->refused)->toBe([
                '@inertiajs/core' => 'assets/refused-inertiajs-core-0e0e0e.js',
                '@inertiajs/react' => 'assets/refused-inertiajs-react-0f0f0f.js',
            ])
            ->and($build->integrity)->toBe(array_fill_keys(array_values(array_filter($build->files, static fn (string $file): bool => str_ends_with($file, '.js'))), $integrity));
    } finally {
        $fixture->remove();
    }
});

it('refuses a build that lacks the entry of a shared or refused module, or one marked as no entry', function (string $key, bool $remove): void {
    $manifest = FixtureBuild::MANIFEST;

    if ($remove) {
        unset($manifest[$key]);
    } else {
        $manifest[$key]['isEntry'] = false;
    }

    $fixture = FixtureBuild::write($manifest);
    $chunk = FixtureBuild::MODULES[$key];

    try {
        expect($fixture->read(...))->toThrow(PanelBuildUnavailable::class, "it has no entry chunk {$chunk['name']} for the module ".substr($key, strlen('cms-panel-module:')).'.');
    } finally {
        $fixture->remove();
    }
})->with([
    'react missing' => ['cms-panel-module:react', true],
    'react/jsx-runtime marked as no entry' => ['cms-panel-module:react/jsx-runtime', false],
    '@inertiajs/react missing' => ['cms-panel-module:@inertiajs/react', true],
]);

it('refuses a build whose script it names cannot be read', function (): void {
    $fixture = FixtureBuild::write();

    try {
        unlink($fixture->directory.'/assets/app-1a2b3c.js');

        expect($fixture->read(...))->toThrow(PanelBuildUnavailable::class, "it names {$fixture->directory}/assets/app-1a2b3c.js, which does not exist or cannot be read.");
    } finally {
        $fixture->remove();
    }
});

it('names the same shared and refused modules as the panel\'s build, js/panel/shared-modules.json', function (): void {
    $modules = json_decode((string) file_get_contents(Codebase::root().'/js/panel/shared-modules.json'), true, 4, JSON_THROW_ON_ERROR);

    expect($modules)->toBe(['shared' => ViteManifest::SHARED, 'refused' => ViteManifest::REFUSED]);
});
