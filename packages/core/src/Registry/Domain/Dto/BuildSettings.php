<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeSelection;

/**
 * The installation's settings that cms:build compiles with the declarations (PRD 13.4, 13.8):
 *
 * - allowed: the Composer packages of the addons the installation allows, from
 *   cbox-cms.addons.allowed, or null when the build does not hold the manifests to a list, as
 *   the compiler's own tests build. cms:build always passes the configured list, and refuses a
 *   manifest whose package is not on it, as registry_addon_not_allowed.
 * - overrides and replacements: cbox-cms.panel.contributions and cbox-cms.panel.replacements.
 * - themes: the panel themes the installation selects, cbox-cms.panel.themes, in their order, and
 *   the file of its own theme, cbox-cms.panel.app_theme.
 * - problems: what could not be read of them, as registry_panel_override_invalid, of the
 *   themes as registry_panel_theme_invalid and of the publishers as registry_panel_bundle_unsigned.
 * - signatures: the publisher keys the installation trusts for the addons' panel bundles,
 *   cbox-cms.addons.publishers, and whether the environment is local, which alone accepts a bundle
 *   of an addon without trusted keys unsigned (PRD 13.8); or null when the build does not hold the
 *   bundles to their signatures, as the compiler's own tests build. cms:build always passes it.
 */
#[Experimental]
final readonly class BuildSettings
{
    /**
     * @param  list<string>|null  $allowed
     * @param  list<ContributionOverride>  $overrides
     * @param  list<ReplacementChoice>  $replacements
     * @param  list<BuildProblem>  $problems
     */
    public function __construct(
        public ?array $allowed = null,
        public array $overrides = [],
        public array $replacements = [],
        public array $problems = [],
        public ThemeSelection $themes = new ThemeSelection,
        public ?SignaturePolicy $signatures = null,
    ) {}

    /**
     * Whether the installation allows the addon of the package.
     */
    public function allows(string $package): bool
    {
        return $this->allowed === null || in_array($package, $this->allowed, true);
    }
}
