<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The stylesheet of the theme cms:build composed from the themes the installation selects, the
 * cascade layer cms.theme (PRD 13.4), as the panel serves it from its own origin: its text and a
 * version, the first 16 hex digits of its SHA-256, which its address carries so it can be cached
 * for good; both null when no theme is selected.
 */
#[Internal]
final readonly class PanelTheme
{
    /** The form of a stylesheet's version in its address. */
    public const string VERSION_PATTERN = '[0-9a-f]{16}';

    public ?string $version;

    public function __construct(public ?string $css)
    {
        $this->version = $css === null ? null : substr(hash('sha256', $css), 0, 16);
    }
}
