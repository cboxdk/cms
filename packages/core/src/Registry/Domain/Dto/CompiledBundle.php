<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\BundlePath;

/**
 * An addon's panel bundle as cms:build checked it (PRD 13.4): its entry module and every file with
 * its SHA-384, sorted by path. The panel serves only these files, by this hash, from the bundle
 * directory the addon's manifest names; the registry holds no path on disk.
 */
#[Experimental]
final readonly class CompiledBundle
{
    /** @var list<BundleFile> */
    public array $files;

    /**
     * @param  list<BundleFile>  $files
     */
    public function __construct(
        public BundlePath $entry,
        array $files,
    ) {
        usort($files, static fn (BundleFile $a, BundleFile $b): int => strcmp($a->path->value, $b->path->value));
        $this->files = $files;
    }
}
