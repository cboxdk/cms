<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;

/**
 * A token that more than one selected theme sets in one place, the whole panel or one part hook:
 * the last of them in the selection wins, and cms:build says so.
 */
#[Experimental]
final readonly class TokenOverlap
{
    /**
     * @param  string|null  $part  the part hook, or null for the whole panel
     * @param  list<ThemeName>  $themes  in the order of the selection
     */
    public function __construct(
        public string $token,
        public ?string $part,
        public array $themes,
    ) {}
}
