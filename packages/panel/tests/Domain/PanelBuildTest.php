<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Domain;

use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use InvalidArgumentException;

/*
 * The panel's build (PRD 13.4): only the files its manifest names can be served, and none of them
 * reaches outside the build's directory.
 */

/**
 * @param  list<string>  $files
 */
function panelBuild(string $directory = '/srv/panel/dist', string $entry = 'assets/app.js', array $files = ['assets/app.css', 'assets/app.js']): PanelBuild
{
    return new PanelBuild($directory, $entry, ['assets/app.css'], [], $files, str_repeat('a', 64));
}

it('serves exactly the files of the build, by their path below its directory', function (): void {
    $build = panelBuild();

    expect($build->serves('assets/app.js'))->toBeTrue()
        ->and($build->serves('assets/other.js'))->toBeFalse()
        ->and($build->serves('.vite/manifest.json'))->toBeFalse()
        ->and($build->pathOf('assets/app.js'))->toBe('/srv/panel/dist/assets/app.js')
        ->and(fn (): string => $build->pathOf('assets/other.js'))->toThrow(InvalidArgumentException::class, 'has no file assets/other.js');
});

it('tells a file of a build from a path that leaves it or is hidden', function (string $file, bool $isFile): void {
    expect(PanelBuild::isFile($file))->toBe($isFile);
})->with([
    'a file' => ['assets/app-1a2b.js', true],
    'a file at the root' => ['app.js', true],
    'a parent segment' => ['assets/../../composer.json', false],
    'a dot segment' => ['assets/./app.js', false],
    'an absolute path' => ['/etc/passwd', false],
    'a hidden file' => ['.vite/manifest.json', false],
    'a backslash' => ['assets\\app.js', false],
    'a URL' => ['https://example.com/app.js', false],
    'an empty segment' => ['assets//app.js', false],
]);

it('refuses a build that names a file outside it, loads a file it does not have, or lies in no local directory', function (callable $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a file outside' => [fn (): PanelBuild => panelBuild(files: ['../app.js', 'assets/app.css', 'assets/app.js']), 'which is not a relative path inside the build'],
    'an entry it does not have' => [fn (): PanelBuild => panelBuild(entry: 'assets/missing.js'), 'loads assets/missing.js, which is not one of its files'],
    'a relative directory' => [fn (): PanelBuild => panelBuild(directory: 'dist'), 'is not an absolute local path'],
    'a stream wrapper' => [fn (): PanelBuild => panelBuild(directory: 'phar:///srv/panel.phar'), 'is not an absolute local path'],
    'a version that is no SHA-256' => [fn (): PanelBuild => new PanelBuild('/srv', 'a.js', [], [], ['a.js'], 'v1'), 'is not a SHA-256'],
]);
