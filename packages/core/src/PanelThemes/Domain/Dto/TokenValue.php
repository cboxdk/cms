<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\Mode;

/**
 * A token's value in the light and the dark mode. In the catalogue a value may hold references to
 * other tokens, `{color-accent}`; a theme's values are literals.
 */
#[Experimental]
final readonly class TokenValue
{
    public function __construct(
        public string $light,
        public string $dark,
    ) {}

    public static function both(string $value): self
    {
        return new self($value, $value);
    }

    public function in(Mode $mode): string
    {
        return $mode === Mode::Light ? $this->light : $this->dark;
    }

    /**
     * Whether the value differs between the modes.
     */
    public function modal(): bool
    {
        return $this->light !== $this->dark;
    }
}
