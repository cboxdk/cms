<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;

/**
 * The selected themes composed in their order over the catalogue (PRD 13.4): the value each token
 * that a theme sets takes on the whole panel, and on each part hook a theme names, the last theme
 * winning, with every token more than one theme set in one place. Tokens no theme sets keep the
 * catalogue's values.
 */
#[Experimental]
final readonly class ComposedTheme
{
    /**
     * @param  list<ThemeName>  $themes  in the order they composed
     * @param  array<string, TokenValue>  $tokens  by token name, sorted
     * @param  array<string, array<string, TokenValue>>  $parts  by part name, then token name, both sorted
     * @param  list<TokenOverlap>  $overlaps
     */
    public function __construct(
        public TokenCatalogue $catalogue,
        public array $themes = [],
        public array $tokens = [],
        public array $parts = [],
        public array $overlaps = [],
    ) {}

    /**
     * Whether no theme sets anything.
     */
    public function isEmpty(): bool
    {
        return $this->tokens === [] && $this->parts === [];
    }

    /**
     * The values set in a place: the whole panel, or a part hook over it.
     *
     * @return array<string, TokenValue>
     */
    public function valuesAt(?string $part): array
    {
        return $part === null ? $this->tokens : array_merge($this->tokens, $this->parts[$part] ?? []);
    }
}
