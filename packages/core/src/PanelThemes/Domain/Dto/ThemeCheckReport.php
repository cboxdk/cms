<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;

/**
 * What cms:panel:theme:check found in one theme file: the problems cms:build would refuse it with
 * if it were the only theme selected, none when it passes, and the number of token values it sets
 * on the whole panel and on the part hooks.
 */
#[Experimental]
final readonly class ThemeCheckReport
{
    /**
     * @param  list<BuildProblem>  $problems
     */
    public function __construct(
        public string $file,
        public array $problems,
        public int $tokens = 0,
        public int $partTokens = 0,
    ) {}

    public function passed(): bool
    {
        return $this->problems === [];
    }
}
