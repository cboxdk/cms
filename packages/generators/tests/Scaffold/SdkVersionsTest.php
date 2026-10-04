<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold;

use Cbox\Cms\Generators\Scaffold\Domain\SdkVersions;
use Cbox\Cms\Tests\Support\Node;

/*
 * The versions cms:make:addon-ui puts in a scaffolded addon's package.json are the ones this
 * repository builds and tests the SDK with (PRD 13.4): the SDK's own version, every development
 * dependency at the version the monorepo's own manifests pin, the SDK's peers among them, and
 * the Node range of the monorepo. They cannot drift from the SDK.
 */

it('names the SDK at its own version and the Node range of the monorepo', function (): void {
    $sdk = Node::jsonFile('js/panel-sdk/package.json');
    $root = Node::jsonFile('package.json');

    expect(SdkVersions::SDK)->toBe($sdk['name'] ?? null)
        ->and(SdkVersions::SDK_VERSION)->toBe($sdk['version'] ?? null)
        ->and(SdkVersions::NODE)->toBe(is_array($root['engines'] ?? null) ? $root['engines']['node'] ?? null : null);
});

it('pins every development dependency at the version the monorepo pins, sorted', function (): void {
    $pinned = [];

    foreach (['package.json', 'js/panel-sdk/package.json', 'js/tooling/package.json'] as $manifest) {
        $package = Node::jsonFile($manifest);

        foreach (['dependencies', 'devDependencies', 'peerDependencies'] as $kind) {
            foreach (is_array($package[$kind] ?? null) ? $package[$kind] : [] as $name => $version) {
                $pinned[(string) $name] = $version;
            }
        }
    }

    $names = array_keys(SdkVersions::DEVELOPMENT);
    $sorted = $names;
    sort($sorted, SORT_STRING);

    expect($names)->toBe($sorted)
        ->and($names)->toContain('react', 'react-dom', 'typescript', 'eslint', 'prettier', 'vite', 'vitest', 'jsdom', 'axe-core');

    foreach (SdkVersions::DEVELOPMENT as $name => $version) {
        $monorepo = $pinned[$name] ?? null;

        expect($monorepo)->toBe($version, sprintf('%s is %s in the scaffold and %s in the monorepo.', $name, $version, is_string($monorepo) ? $monorepo : 'absent'));
    }

    $peers = Node::jsonFile('js/panel-sdk/package.json')['peerDependencies'] ?? [];

    foreach (is_array($peers) ? $peers : [] as $peer => $version) {
        expect(SdkVersions::DEVELOPMENT[$peer] ?? null)->toBe($version, sprintf("The SDK's peer %s is not in the scaffold's development dependencies.", $peer));
    }
});
