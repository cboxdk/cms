<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\BundleFileKind;
use Cbox\Cms\Core\Registry\Domain\BundleIntegrity;
use Cbox\Cms\Core\Registry\Domain\BundlePath;

/**
 * One file of an addon's panel bundle (PRD 13.4): its path in the bundle, its SHA-384 and what it
 * is. The panel serves only the files the compiled registry lists, by this hash.
 */
#[Experimental]
final readonly class BundleFile
{
    public function __construct(
        public BundlePath $path,
        public BundleIntegrity $integrity,
        public BundleFileKind $kind,
    ) {}
}
