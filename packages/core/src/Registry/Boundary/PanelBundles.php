<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PanelBundleCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PanelBundleSignatureCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Registry\Domain\AddonLayer;
use Cbox\Cms\Core\Registry\Domain\BundleFileKind;
use Cbox\Cms\Core\Registry\Domain\BundleIntegrity;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonBundle;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleSigning;

/**
 * Reads an addon's panel bundle from its directory (PRD 13.4): panel-manifest.json through the
 * generated codec of panel-bundle.v1.json, then every file the manifest lists, whose bytes must
 * have the manifest's SHA-384 and, for a stylesheet, keep every rule in the addon's cascade layer
 * (AddonLayer). What is wrong is described in the AddonBundle, which the compiler reports as
 * registry_panel_bundle_invalid with its own checks of the manifest. The publisher's signature
 * (PRD 13.8) is read from panel-signature.json beside the manifest through the generated codec of
 * panel-bundle-signature.v1.json, with the manifest's bytes it signs, into the bundle's signing;
 * the compiler holds it to the keys the installation trusts.
 */
#[Internal]
final readonly class PanelBundles
{
    /** The file of a bundle's manifest, in the bundle's directory. */
    public const string MANIFEST = 'panel-manifest.json';

    /** The file of the publisher's signature over the manifest, in the bundle's directory. */
    public const string SIGNATURE = 'panel-signature.json';

    public static function read(string $directory): AddonBundle
    {
        $path = rtrim($directory, '/').'/'.self::MANIFEST;
        $json = LocalFiles::read($path);

        if ($json === null) {
            return new AddonBundle(null, [sprintf('%s is missing or unreadable; build the addon\'s UI so the bundle holds it', self::MANIFEST)]);
        }

        try {
            $manifest = new PanelBundleCodecV1()->decode($json, ClassificationAccess::Public);
        } catch (DecodingFailed $invalid) {
            return new AddonBundle(null, [sprintf('%s is not a document of panel-bundle.v1.json: %s', self::MANIFEST, $invalid->getMessage())]);
        }

        $signing = self::signing($directory, $json);

        $problems = [];
        $seen = [];

        foreach ($manifest->files as $file) {
            $name = $file->path->value;

            if (isset($seen[$name])) {
                $problems[] = sprintf('%s lists %s twice', self::MANIFEST, $name);

                continue;
            }

            $seen[$name] = true;
            $bytes = LocalFiles::read(rtrim($directory, '/').'/'.$name);

            if ($bytes === null) {
                $problems[] = sprintf('the file %s is missing or unreadable', $name);

                continue;
            }

            $integrity = BundleIntegrity::of($bytes);

            if (! $integrity->equals($file->integrity)) {
                $problems[] = sprintf('the file %s has the SHA-384 %s, and %s says %s, so it changed after the bundle was built', $name, $integrity->value, self::MANIFEST, $file->integrity->value);

                continue;
            }

            $unlayered = $file->kind === BundleFileKind::Style ? AddonLayer::firstUnlayered($bytes) : null;

            if ($unlayered !== null) {
                $problems[] = sprintf('the stylesheet %s has a rule outside the cascade layer %s, at "%s"; keep every rule in @layer %s', $name, AddonLayer::LAYER, $unlayered, AddonLayer::LAYER);
            }
        }

        return new AddonBundle($manifest, $problems, $signing);
    }

    /**
     * The manifest's bytes with the signature of panel-signature.json, when the bundle has one.
     */
    private static function signing(string $directory, string $document): BundleSigning
    {
        $path = rtrim($directory, '/').'/'.self::SIGNATURE;

        if (! is_file($path)) {
            return new BundleSigning($document, null);
        }

        $json = LocalFiles::read($path);

        if ($json === null) {
            return new BundleSigning($document, null, sprintf('%s is unreadable', self::SIGNATURE));
        }

        try {
            return new BundleSigning($document, new PanelBundleSignatureCodecV1()->decode($json, ClassificationAccess::Public));
        } catch (DecodingFailed $invalid) {
            return new BundleSigning($document, null, sprintf('%s is not a document of panel-bundle-signature.v1.json: %s', self::SIGNATURE, $invalid->getMessage()));
        }
    }
}
