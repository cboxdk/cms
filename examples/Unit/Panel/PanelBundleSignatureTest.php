<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PanelBundleSignatureCodecV1;
use Cbox\Cms\Core\Registry\Domain\PublisherKey;
use Cbox\Cms\Core\Registry\Domain\SignatureAlgorithm;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The approvals addon's dist/panel-signature.json is a document of panel-bundle-signature.v1.json:
 * its generated codec reads it, and the publisher's Ed25519 signature verifies over the bytes of
 * dist/panel-manifest.json with the public key it names, the key an installation puts in
 * cbox-cms.addons.publishers for the addon. A manifest changed after the signing does not verify.
 */
final class PanelBundleSignatureTest extends TestCase
{
    #[Test]
    public function it_reads_the_signature_and_verifies_it_over_the_manifest(): void
    {
        $directory = __DIR__.'/Approvals/dist';
        $manifest = file_get_contents($directory.'/panel-manifest.json');
        $json = file_get_contents($directory.'/panel-signature.json');
        self::assertIsString($manifest);
        self::assertIsString($json);

        $signature = new PanelBundleSignatureCodecV1()->decode($json, ClassificationAccess::Public);

        self::assertSame(SignatureAlgorithm::Ed25519, $signature->algorithm);
        self::assertTrue($signature->publicKey->equals(new PublisherKey('KvpGeOheh2ZDEsrHAy/wRzWWnJdCf1AcwubMOddwn3Y=')));
        self::assertTrue($signature->signature->verifies($manifest, $signature->publicKey));
        self::assertFalse($signature->signature->verifies(str_replace('badge.js', 'other.js', $manifest), $signature->publicKey));
    }
}
