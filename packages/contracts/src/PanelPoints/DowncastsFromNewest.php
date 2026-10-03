<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The declared downcast of an older version of a panel point (PRD 13.4): when a breaking change to
 * a point's props makes its next version, `<name>@2`, the panel builds the props of the newest
 * version only, and the props class of every older version implements this interface and builds
 * its own props from the newest version's, so a contribution to `<name>@1` keeps working.
 *
 * TNewest is the props class of the newest version of the point, which every older version
 * downcasts from directly; when a version after it arrives, each older version's downcast moves to
 * it. cms:build refuses a point name whose older versions do not all implement it with
 * registry_panel_point_without_downcast. A downcast is a pure function of the newest props: it reads
 * them and builds the older props, and never reads anything else.
 *
 * @template TNewest of object
 */
#[Experimental]
interface DowncastsFromNewest
{
    /**
     * The props of this version of the point, built from the props of its newest version.
     *
     * @param  TNewest  $newest
     */
    public static function downcast(object $newest): static;
}
