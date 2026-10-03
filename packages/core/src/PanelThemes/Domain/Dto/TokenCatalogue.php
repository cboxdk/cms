<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The kit's design tokens as js/ui-kit/tokens.json lists them (PRD 13.4): every token in the
 * catalogue's order, the contrast pairs and the names of the curated part hooks, sorted. The JSON
 * is the single source; js/ui-kit/scripts/tokens.js checks it, and gate 6 holds what it generates
 * to it.
 */
#[Experimental]
final readonly class TokenCatalogue
{
    /** @var array<string, CatalogueToken> */
    private array $byName;

    /**
     * @param  list<CatalogueToken>  $tokens
     * @param  list<ContrastPair>  $contrast
     * @param  list<string>  $parts
     */
    public function __construct(
        public array $tokens,
        public array $contrast,
        public array $parts,
    ) {
        $byName = [];

        foreach ($tokens as $token) {
            $byName[$token->name] = $token;
        }

        $this->byName = $byName;
    }

    public function token(string $name): ?CatalogueToken
    {
        return $this->byName[$name] ?? null;
    }

    public function hasPart(string $name): bool
    {
        return in_array($name, $this->parts, true);
    }
}
