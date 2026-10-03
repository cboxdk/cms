<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildWarning;

/**
 * The panel's theme as cms:build compiles it (PRD 13.4): the stylesheet of the cascade layer
 * cms.theme, the empty string when no theme is selected or a problem stops it, the problems that
 * refuse the build, and the warnings it prints, one per token more than one selected theme sets.
 */
#[Experimental]
final readonly class ThemeCompilation
{
    /**
     * @param  list<BuildProblem>  $problems
     * @param  list<BuildWarning>  $warnings
     */
    public function __construct(
        public string $stylesheet = '',
        public array $problems = [],
        public array $warnings = [],
    ) {}
}
