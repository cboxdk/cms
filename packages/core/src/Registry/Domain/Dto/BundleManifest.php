<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Core\Registry\Domain\BundlePath;

/**
 * What an addon's panel bundle says of itself in its panel-manifest.json (PRD 13.4), the document
 * of panel-bundle.v1.json: the entry module, every file with its SHA-384, the shared modules it
 * imports, and the ids of the contributions it registers code for.
 */
#[Experimental]
final readonly class BundleManifest
{
    /**
     * @param  list<BundleFile>  $files
     * @param  list<string>  $externals  bare module specifiers, such as "react"
     * @param  list<ContributionId>  $contributions
     */
    public function __construct(
        public BundlePath $entry,
        public array $files,
        public array $externals,
        public array $contributions,
    ) {}
}
