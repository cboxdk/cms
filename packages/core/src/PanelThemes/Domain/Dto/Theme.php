<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;

/**
 * A panel theme, read and checked (theme.v1.json): the token values it sets on the whole panel,
 * and those it sets on each curated part hook, every one a literal of its token's type in both
 * modes. A theme is data only: it sets tokens and nothing else.
 */
#[Experimental]
final readonly class Theme
{
    /**
     * @param  array<string, TokenValue>  $tokens  by token name
     * @param  array<string, array<string, TokenValue>>  $parts  by part name, then token name
     */
    public function __construct(
        public ThemeName $name,
        public array $tokens = [],
        public array $parts = [],
    ) {}
}
