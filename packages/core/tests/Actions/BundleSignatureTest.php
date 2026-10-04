<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Core\Registry\Boundary\PanelBundles;
use Cbox\Cms\Core\Registry\Domain\BundleIntegrity;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\SignaturePolicy;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Tests\Registry\PanelBuildWorld;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;

/*
 * cms:build holds an addon's panel bundle to its publisher's signature (PRD 13.8, decision D8 of
 * the panel extension architecture): panel-signature.json carries an Ed25519 signature over the
 * bytes of panel-manifest.json by the publisher's key, and the build refuses the bundle with
 * registry_panel_bundle_unsigned when the signature is missing, by a key the installation does
 * not trust for the addon in cbox-cms.addons.publishers, or does not verify because the manifest
 * changed after the signing. A bundle of an addon the installation trusts no key for passes
 * unsigned in the local environment alone.
 */

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

/**
 * The problems of a build of the addon fixture's bundle, signed by $signer when given and
 * changed by $after, under the policy; empty when it builds.
 *
 * @param  non-empty-string|null  $signer
 * @param  (callable(string): void)|null  $after
 * @return list<string>
 */
function signatureProblems(?string $signer, SignaturePolicy $policy, ?callable $after = null): array
{
    $manifest = PanelBuildWorld::manifest([new SlotFill(new ContributionId('approvals.badge'), 'notes.legacy@1')]);
    $addons = PanelBuildWorld::addons([$manifest], [PanelBuildWorld::ADDON => PanelBuildWorld::writtenBundle($manifest, $signer, $after)]);
    $settings = PanelBuildWorld::settings(signatures: $policy);

    try {
        $registry = PanelBuildWorld::build($addons, $settings);
    } catch (RegistryBuildFailed $failed) {
        return array_map(static fn (BuildProblem $problem): string => $problem->describe(), $failed->problems);
    }

    expect($registry->addons[0]->panel?->bundle?->entry->value)->toBe('addon.js');

    return [];
}

it('builds a bundle signed by a key the installation trusts for the addon, in every environment', function (): void {
    $publisher = PanelBuildWorld::keypair();

    expect(signatureProblems($publisher, PanelBuildWorld::signatures([$publisher])))->toBe([])
        ->and(signatureProblems($publisher, PanelBuildWorld::signatures([PanelBuildWorld::keypair(), $publisher], local: true)))->toBe([]);
});

it('refuses a bundle signed by a key the installation does not trust for the addon', function (): void {
    $trusted = PanelBuildWorld::keypair();
    $other = PanelBuildWorld::keypair();
    $problems = signatureProblems($other, PanelBuildWorld::signatures([$trusted], local: true));

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toStartWith('[registry_panel_bundle_unsigned] The panel bundle of addon "approvals" (acme/cms-approvals) in '.PanelBuildWorld::BUNDLE.': it is signed by the key '.PanelBuildWorld::publisherKey($other)->value.', which the installation does not trust for the addon; it trusts the key '.PanelBuildWorld::publisherKey($trusted)->value)
        ->and($problems[0])->toContain('cbox-cms.addons.publishers');
});

it('refuses a bundle whose manifest changed after the signing: a file and its hash, so the files still match', function (): void {
    $publisher = PanelBuildWorld::keypair();
    $problems = signatureProblems($publisher, PanelBuildWorld::signatures([$publisher]), static function (string $directory): void {
        $script = 'export default { changed: true };';
        $manifest = (string) file_get_contents($directory.'/'.PanelBundles::MANIFEST);
        file_put_contents($directory.'/addon.js', $script);
        file_put_contents($directory.'/'.PanelBundles::MANIFEST, str_replace(BundleIntegrity::of('export default {};')->value, BundleIntegrity::of($script)->value, $manifest));
    });

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toStartWith('[registry_panel_bundle_unsigned]')
        ->and($problems[0])->toContain('its signature by the key '.PanelBuildWorld::publisherKey($publisher)->value.' does not verify over panel-manifest.json, so the manifest changed after the bundle was signed');
});

it('refuses a bundle without a signature when the installation trusts a key for the addon, also in local', function (): void {
    $publisher = PanelBuildWorld::keypair();

    foreach ([false, true] as $local) {
        $problems = signatureProblems(null, PanelBuildWorld::signatures([$publisher], local: $local));

        expect($problems)->toHaveCount(1)
            ->and($problems[0])->toStartWith('[registry_panel_bundle_unsigned]')
            ->and($problems[0])->toContain('it is not signed, and the installation trusts the key '.PanelBuildWorld::publisherKey($publisher)->value.' for the addon');
    }
});

it('accepts a bundle of an addon without trusted keys in the local environment alone, signed or not', function (): void {
    $publisher = PanelBuildWorld::keypair();
    $unsigned = signatureProblems(null, PanelBuildWorld::signatures([]));
    $foreign = signatureProblems($publisher, PanelBuildWorld::signatures([]));

    expect(signatureProblems(null, PanelBuildWorld::signatures([], local: true)))->toBe([])
        ->and(signatureProblems($publisher, PanelBuildWorld::signatures([], local: true)))->toBe([])
        ->and($unsigned)->toHaveCount(1)
        ->and($unsigned[0])->toContain('[registry_panel_bundle_unsigned]')->toContain('it is not signed, and the installation trusts no publisher key for the addon')->toContain('only the local environment accepts the bundle without one')
        ->and($foreign)->toHaveCount(1)
        ->and($foreign[0])->toContain('it is signed by the key '.PanelBuildWorld::publisherKey($publisher)->value.', and the installation trusts no publisher key for the addon');
});

it('refuses a signature file that is no document of panel-bundle-signature.v1.json', function (): void {
    $publisher = PanelBuildWorld::keypair();
    $problems = signatureProblems($publisher, PanelBuildWorld::signatures([$publisher]), static function (string $directory): void {
        file_put_contents($directory.'/'.PanelBundles::SIGNATURE, '{"algorithm": "rsa", "public_key": "x", "signature": "y"}');
    });

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toStartWith('[registry_panel_bundle_unsigned]')
        ->and($problems[0])->toContain('panel-signature.json is not a document of panel-bundle-signature.v1.json');
});

it('leaves the signatures unchecked when the build holds no policy, as the compiler\'s own tests build', function (): void {
    $manifest = PanelBuildWorld::manifest([new SlotFill(new ContributionId('approvals.badge'), 'notes.legacy@1')]);
    $registry = PanelBuildWorld::build(PanelBuildWorld::addons([$manifest], [PanelBuildWorld::ADDON => PanelBuildWorld::writtenBundle($manifest)]));

    expect($registry->addons[0]->panel?->bundle?->entry->value)->toBe('addon.js');
});
