<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Boundary;

use Cbox\Cms\Panel\Boundary\ViteManifest;
use Cbox\Cms\Panel\Domain\PanelBuildUnavailable;
use Cbox\Cms\Panel\Tests\FixtureBuild;

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
                'assets/shared-3c4d5e.css',
                'assets/shared-4d5e6f.js',
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
    $fixture = FixtureBuild::write([
        'src/app.tsx' => ['file' => 'assets/app.js', 'isEntry' => true, 'imports' => ['_a.js']],
        '_a.js' => ['file' => 'assets/a.js', 'imports' => ['_b.js'], 'css' => ['assets/a.css']],
        '_b.js' => ['file' => 'assets/b.js', 'imports' => ['_a.js', 'src/app.tsx'], 'css' => ['assets/a.css']],
    ]);

    try {
        $build = $fixture->read();

        expect($build->preloads)->toBe(['assets/b.js', 'assets/a.js'])
            ->and($build->styles)->toBe(['assets/a.css']);
    } finally {
        $fixture->remove();
    }
});
