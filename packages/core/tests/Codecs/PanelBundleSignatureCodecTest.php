<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PanelBundleSignatureCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Registry\Domain\Dto\BundleSignature;
use Cbox\Cms\Core\Registry\Domain\Ed25519Signature;
use Cbox\Cms\Core\Registry\Domain\PublisherKey;
use Cbox\Cms\Core\Registry\Domain\SignatureAlgorithm;

/*
 * The generated codec of panel-bundle-signature.v1.json (GUARDRAILS 2.2, PRD 13.8): the
 * publisher's signature of a panel bundle round-trips through it, valid against the schema, and
 * a document whose key or signature is not of its length is refused at its path.
 */

it('writes a bundle signature valid against panel-bundle-signature.v1.json and reads it back', function (): void {
    $keypair = sodium_crypto_sign_keypair();
    $signature = new BundleSignature(
        SignatureAlgorithm::Ed25519,
        new PublisherKey(base64_encode(sodium_crypto_sign_publickey($keypair))),
        new Ed25519Signature(base64_encode(sodium_crypto_sign_detached('{"entry": "addon.js"}', sodium_crypto_sign_secretkey($keypair)))),
    );
    $codec = new PanelBundleSignatureCodecV1;
    $json = $codec->encode($signature, ClassificationAccess::Public);

    expect(KernelSchema::errors('panel-bundle-signature.v1.json', $json, KernelSchema::CORE_DIRECTORY))->toBe([])
        ->and($json)->toStartWith('{"algorithm":"ed25519","public_key":"')
        ->and($codec->decode($json, ClassificationAccess::Public))->toEqual($signature);
});

it('refuses a document whose values are not of their form, at the value\'s path', function (string $json, string $path): void {
    $failed = null;

    try {
        new PanelBundleSignatureCodecV1()->decode($json, ClassificationAccess::Public);
    } catch (DecodingFailed $caught) {
        $failed = $caught;
    }

    expect($failed?->path?->toString())->toBe($path);
})->with([
    'an unknown algorithm' => ['{"algorithm": "rsa", "public_key": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", "signature": "'.str_repeat('A', 86).'=="}', 'algorithm'],
    'a key of another length' => ['{"algorithm": "ed25519", "public_key": "AAAA", "signature": "'.str_repeat('A', 86).'=="}', 'public_key'],
    'a signature of another length' => ['{"algorithm": "ed25519", "public_key": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", "signature": "AAAA"}', 'signature'],
    'a missing signature' => ['{"algorithm": "ed25519", "public_key": "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="}', 'signature'],
]);
