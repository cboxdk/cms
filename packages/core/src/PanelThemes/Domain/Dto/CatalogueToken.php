<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\Tier;
use Cbox\Cms\Core\PanelThemes\Domain\ValueType;

/**
 * A design token of the kit's catalogue, js/ui-kit/tokens.json: its name without the `--cms-`
 * prefix, its tier, the type of its value and its value per mode as the catalogue writes it, with
 * references to other tokens.
 */
#[Experimental]
final readonly class CatalogueToken
{
    public function __construct(
        public string $name,
        public Tier $tier,
        public ValueType $type,
        public TokenValue $value,
    ) {}

    /**
     * Whether a theme may set the token: the semantic and the component tiers, never a primitive.
     */
    public function themeable(): bool
    {
        return $this->tier !== Tier::Primitive;
    }
}
