<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where the order or the enabled state of a contribution to a panel point comes from (PRD 13.4):
 * the addon's manifest, the installation's settings that cms:build compiles
 * (cbox-cms.panel.contributions and cbox-cms.panel.replacements), or the activation state the
 * panel reads at each request (cbox-cms.panel.disabled).
 */
#[Experimental]
enum FillSource: string
{
    case Addon = 'addon';
    case Installation = 'installation';
    case Activation = 'activation';
}
