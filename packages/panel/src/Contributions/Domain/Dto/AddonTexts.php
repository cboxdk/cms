<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The texts one addon's contributions read on a page (section 2.6 of the panel extension
 * architecture): the addon's namespace and the texts of the active locale by key, sorted, as
 * cms:build compiled its catalogue. Only the active locale's texts travel to the browser, where a
 * contribution's t() reads them through the host.
 */
#[Experimental]
final readonly class AddonTexts
{
    /**
     * @param  array<string, string>  $texts  by key, sorted
     */
    public function __construct(
        public AddonNamespace $addon,
        public array $texts,
    ) {}
}
