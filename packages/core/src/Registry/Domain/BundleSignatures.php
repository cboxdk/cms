<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleSignature;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleSigning;
use Cbox\Cms\Core\Registry\Domain\Dto\SignaturePolicy;

/**
 * Holds an addon's panel bundle to its publisher's signature (PRD 13.8, decision D8 of the panel
 * extension architecture). The signature in panel-signature.json is an Ed25519 signature over the
 * bytes of panel-manifest.json, which carries the SHA-384 of every file, so a verified signature
 * vouches for the whole bundle. The bundle passes when the signature's key is one the installation
 * trusts for the addon and the signature verifies. An installation that trusts no key for the
 * addon accepts the bundle, signed or not, in the local environment alone, so an addon's UI can be
 * developed before its publisher's key is known, and never anywhere else.
 */
#[Internal]
final readonly class BundleSignatures
{
    /** The setting that names the keys, in the reasons. */
    public const string SETTING = 'cbox-cms.addons.publishers';

    /**
     * Why the bundle of the package does not pass, or null when it does.
     */
    public static function refusal(string $package, BundleSigning $signing, SignaturePolicy $policy): ?string
    {
        if ($signing->problem !== null) {
            return $signing->problem;
        }

        $keys = $policy->keysOf($package);
        $signature = $signing->signature;

        if ($keys === []) {
            if ($policy->unsignedAllowed) {
                return null;
            }

            return sprintf(
                '%s, and the installation trusts no publisher key for the addon. Add the publisher\'s Ed25519 public key to %s under \'%s\'; only the local environment accepts the bundle without one.',
                $signature instanceof BundleSignature ? 'it is signed by the key '.$signature->publicKey->value : 'it is not signed',
                self::SETTING,
                $package,
            );
        }

        if (! $signature instanceof BundleSignature) {
            return sprintf('it is not signed, and the installation trusts %s for the addon. Build the bundle with the publisher\'s key, so it holds panel-signature.json.', self::named($keys));
        }

        if (! array_any($keys, static fn (PublisherKey $key): bool => $key->equals($signature->publicKey))) {
            return sprintf('it is signed by the key %s, which the installation does not trust for the addon; it trusts %s. Build the bundle with the publisher\'s key, or add this key to %s under \'%s\' after reviewing where it came from.', $signature->publicKey->value, self::named($keys), self::SETTING, $package);
        }

        if (! $signature->signature->verifies($signing->document, $signature->publicKey)) {
            return sprintf('its signature by the key %s does not verify over panel-manifest.json, so the manifest changed after the bundle was signed. Build the bundle again with the publisher\'s key, and do not edit the built files.', $signature->publicKey->value);
        }

        return null;
    }

    /**
     * @param  list<PublisherKey>  $keys
     */
    private static function named(array $keys): string
    {
        return (count($keys) === 1 ? 'the key ' : 'the keys ').implode(', ', array_map(static fn (PublisherKey $key): string => $key->value, $keys));
    }
}
