<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Boundary\PanelBundles;
use Cbox\Cms\Core\Registry\Domain\AddonLayer;
use Cbox\Cms\Core\Registry\Domain\BundleIntegrity;

/*
 * cms:build reads an addon's panel bundle from its directory (PRD 13.4): panel-manifest.json
 * through the generated codec of panel-bundle.v1.json, and every file it lists against its
 * SHA-384, with every stylesheet held to the addon's cascade layer.
 */

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

/**
 * A bundle directory with the files.
 *
 * @param  array<string, string>  $files
 */
function bundleDirectory(array $files): string
{
    $directory = RegistryFixtures::scratch();
    mkdir($directory, 0o755, true);

    foreach ($files as $name => $bytes) {
        file_put_contents($directory.'/'.$name, $bytes);
    }

    return $directory;
}

function bundleManifest(string $css = '@layer cms.addon { .x { color: red; } }'): string
{
    return json_encode([
        'contributions' => ['approvals.badge'],
        'entry' => 'addon.js',
        'externals' => ['react'],
        'files' => [
            ['integrity' => BundleIntegrity::of('export default {};')->value, 'kind' => 'script', 'path' => 'addon.js'],
            ['integrity' => BundleIntegrity::of($css)->value, 'kind' => 'style', 'path' => 'addon.css'],
        ],
    ], JSON_THROW_ON_ERROR);
}

it('reads a bundle whose files are what its manifest says', function (): void {
    $bundle = PanelBundles::read(bundleDirectory(['panel-manifest.json' => bundleManifest(), 'addon.js' => 'export default {};', 'addon.css' => '@layer cms.addon { .x { color: red; } }']));

    expect($bundle->problems)->toBe([])
        ->and($bundle->manifest?->entry->value)->toBe('addon.js')
        ->and(array_map(static fn ($file): string => $file->path->value, $bundle->manifest->files ?? []))->toBe(['addon.js', 'addon.css']);
});

it('says what is wrong with a bundle on disk: a changed hash, a missing file and a rule outside the layer', function (): void {
    $bundle = PanelBundles::read(__DIR__.'/Fixtures/PanelBundle/dist');

    expect($bundle->manifest?->entry->value)->toBe('addon.js')
        ->and($bundle->problems)->toHaveCount(3)
        ->and($bundle->problems[0])->toContain('the stylesheet addon.css has a rule outside the cascade layer cms.addon, at ".approvals-badge { color: red; }"')
        ->and($bundle->problems[1])->toContain('the file addon.js has the SHA-384')->toContain('so it changed after the bundle was built')
        ->and($bundle->problems[2])->toBe('the file missing.png is missing or unreadable');
});

it('refuses a bundle without its manifest, and a manifest that is no document of panel-bundle.v1.json', function (): void {
    $missing = PanelBundles::read(bundleDirectory(['addon.js' => 'export default {};']));
    $invalid = PanelBundles::read(bundleDirectory(['panel-manifest.json' => '{"entry": "../escape.js", "files": [], "externals": [], "contributions": []}']));

    expect($missing->manifest)->toBeNull()
        ->and($missing->problems)->toBe(["panel-manifest.json is missing or unreadable; build the addon's UI so the bundle holds it"])
        ->and($invalid->manifest)->toBeNull()
        ->and($invalid->problems[0])->toStartWith('panel-manifest.json is not a document of panel-bundle.v1.json');
});

it('finds the first rule of a stylesheet outside the addon\'s cascade layer', function (string $css, ?string $unlayered): void {
    expect(AddonLayer::firstUnlayered($css))->toBe($unlayered);
})->with([
    'nothing' => ['', null],
    'one block' => ['@layer cms.addon { .a { color: red; } }', null],
    'a statement and nested blocks' => ["@charset \"utf-8\";\n@layer cms.addon;\n@layer cms.addon.approvals { @media (width > 1px) { .a { content: \"}\"; } } }", null],
    'a comment outside' => ["/* the badge */\n@layer cms.addon { .a {} }", null],
    'a rule before the layer' => ['.a { color: red; } @layer cms.addon {}', '.a { color: red; } @layer cms.addon {}'],
    'the panel\'s own layer' => ['@layer cms.panel { .a {} }', '@layer cms.panel { .a {} }'],
    'a layer list with another layer' => ['@layer cms.addon, cms.theme;', '@layer cms.addon, cms.theme;'],
    'a block never closed' => ['@layer cms.addon { .a {', '.a {'],
    'an import' => ['@import "other.css";', '@import "other.css";'],
]);
