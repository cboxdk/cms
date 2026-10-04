<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Shell\Domain\Shell;

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
 * them. The core contributes the nav entry of the who-am-I page to shell.nav@1 (PRD 13.4): a nav
 * entry is how a module registers a page in the navigation, with the permission the viewer must
 * hold in its Scope (requires), decided per viewer as every contribution's is (ResolveContributions);
 * the who-am-I page requires none, because every actor reads its own self. A module of cboxdk/cms
 * with pages of its own declares their nav entries the same way through its service provider's
 * DeclaresCoreContributions, each pointing at one of the panel's own pages (OwnPage).
 */
#[Internal]
final readonly class CoreContributions
{
    /** The nav entry of the who-am-I page, in the shell's navigation. */
    public const string ACCOUNT_ME_NAV = 'cms.account-me';

    /** The priority of the core's first nav entry; a module's come after it, an addon's at 1000. */
    public const int FIRST = 100;

    /**
     * @return list<PanelContribution>
     */
    public static function all(): array
    {
        return [
            new NavContribution(new ContributionId(self::ACCOUNT_ME_NAV), Shell::NAV.'@1', 'panel.nav.account_me', OwnPage::AccountMe->value, null, self::FIRST),
        ];
    }
}
