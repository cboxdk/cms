<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;

/**
 * The core's own contributions to the panel's points (PRD 13.4), in the namespace cms, which the
 * panel's service provider declares to cms:build (DeclaresCoreContributions). A page that renders
 * a point lists here the items the core gives it, such as the profile section of the who-am-I
 * page, at priorities 100, 200 and so on, so they come before an addon's, whose default is 1000,
 * and an installation reorders or disables them as it does an addon's. Those that run code are
 * registered under their ids by the panel's own JavaScript, js/panel/src/host/core.ts, which the
 * host holds to this list at run time as it holds an addon's bundle to its manifest.
 *
 * The panel's points come with the pages that render them, and so do the core's contributions to
 * them; until a page renders a point, the core contributes nothing.
 */
#[Internal]
final readonly class CoreContributions
{
    /**
     * @return list<PanelContribution>
     */
    public static function all(): array
    {
        return [];
    }
}
