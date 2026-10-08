<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelLocale;

/**
 * An addon's panel texts in one locale (PRD 13.4, section 2.6 of the panel extension
 * architecture): the locale and its texts by key, sorted by key, every key in the addon's own
 * namespace. The panel sends the catalogue of the active locale alone to the browser, where a
 * contribution's t() reads it.
 */
#[Experimental]
final readonly class PanelCatalogue
{
    /**
     * @param  array<string, string>  $texts  by key, sorted
     */
    public function __construct(
        public PanelLocale $locale,
        public array $texts,
    ) {}
}
