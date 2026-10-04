<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\Ed25519Signature;
use Cbox\Cms\Core\Registry\Domain\PublisherKey;
use Cbox\Cms\Core\Registry\Domain\SignatureAlgorithm;

/**
 * The publisher's signature of an addon's panel bundle (PRD 13.8), the document of
 * panel-bundle-signature.v1.json in panel-signature.json: the algorithm, the publisher's public key
 * and the signature over the bytes of panel-manifest.json.
 */
#[Experimental]
final readonly class BundleSignature
{
    public function __construct(
        public SignatureAlgorithm $algorithm,
        public PublisherKey $publicKey,
        public Ed25519Signature $signature,
    ) {}
}
