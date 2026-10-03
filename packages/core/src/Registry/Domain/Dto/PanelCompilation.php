<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What PanelCompiler made of the panel points and the addons' panel contributions (PRD 13.4): the
 * points with their fills, sorted by name and version, the addons, sorted by namespace, the
 * problems that make the build fail and the warnings it prints.
 */
#[Experimental]
final readonly class PanelCompilation
{
    /**
     * @param  list<PanelPointEntry>  $points
     * @param  list<AddonEntry>  $addons
     * @param  list<BuildProblem>  $problems
     * @param  list<BuildWarning>  $warnings
     */
    public function __construct(
        public array $points,
        public array $addons,
        public array $problems,
        public array $warnings,
    ) {}
}
