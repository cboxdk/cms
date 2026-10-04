<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What cms:build read of a panel bundle's signing (PRD 13.8): the bytes of panel-manifest.json,
 * the message the publisher signs, and the signature from panel-signature.json, or null when the
 * bundle has no signature file, or a problem when the file is there and is not a document of
 * panel-bundle-signature.v1.json. BundleSignatures verifies it against the keys the installation
 * trusts for the addon.
 */
#[Experimental]
final readonly class BundleSigning
{
    public function __construct(
        public string $document,
        public ?BundleSignature $signature,
        public ?string $problem = null,
    ) {}
}
