<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;

/**
 * The addon manifests of one cms:build (PRD 13.1, 13.2), as the service providers declared them,
 * and the problems of those that could not be read: a manifest that cannot be built, or whose
 * documentation or schema directory is not a readable directory. The compiler lists the problems
 * with its own and writes nothing when there are any.
 *
 * The bundles are the panel bundles of the manifests that name one (PRD 13.4), by the manifest's
 * package, as the build read them from disk, and the catalogues the panel catalogues of the
 * manifests that name a language directory, the same way.
 *
 * The core's contributions are the panel contributions the modules of cboxdk/cms declare in the
 * namespace cms (DeclaresCoreContributions), compiled with the addons'.
 */
#[Experimental]
final readonly class DeclaredAddons
{
    /**
     * @param  list<AddonManifest>  $manifests
     * @param  list<BuildProblem>  $problems
     * @param  array<string, AddonBundle>  $bundles  by package
     * @param  list<PanelContribution>  $core  the core's own panel contributions
     * @param  array<string, AddonCatalogues>  $catalogues  by package
     */
    public function __construct(
        public array $manifests = [],
        public array $problems = [],
        public array $bundles = [],
        public array $core = [],
        public array $catalogues = [],
    ) {}
}
