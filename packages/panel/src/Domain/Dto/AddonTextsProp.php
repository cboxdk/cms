<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The catalogue of one addon in the active locale (contributions.v1.json, `#/$defs/texts`): its
 * namespace and its texts, sorted by key, as cms:build compiled them from the addon's
 * resources/panel/lang/<locale>.json. The page carries one of these per addon with an active
 * contribution, so only the active locale reaches the browser.
 */
#[Internal]
final readonly class AddonTextsProp
{
    /**
     * @param  list<TextProp>  $entries  sorted by key
     */
    public function __construct(
        public AddonNamespace $addon,
        public array $entries,
    ) {}
}
