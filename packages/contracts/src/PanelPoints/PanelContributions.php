<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\ClassNames;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What an addon adds to the panel (PRD 13.4), the `panel` member of its AddonManifest:
 *
 *     panel: new PanelContributions(
 *         sdk: new PanelApiVersion(1, 0),
 *         bundle: __DIR__.'/../dist/panel',
 *         acceptsExperimental: ['account.me.sections@1'],
 *         contributions: [new SlotFill('approvals.badge', 'account.me.sections@1')],
 *         themes: ['brand' => __DIR__.'/../resources/panel/theme.json'],
 *     ),
 *
 * - sdk: the version of the panel's API the addon's UI needs, read as ^major.minor. cms:build
 *   refuses one the panel does not satisfy, as registry_incompatible_panel_api.
 * - bundle: the absolute directory of the addon's prebuilt bundle, which holds panel-manifest.json
 *   (panel-bundle.v1.json), or null when no contribution runs code. cms:build checks every file
 *   the bundle manifest lists against its SHA-384, its stylesheets against the addon's cascade
 *   layer, its imports against the panel's shared modules and its contributions against these, as
 *   registry_panel_bundle_invalid.
 * - acceptsExperimental: the ids of the experimental points the addon contributes to, each once.
 *   An experimental point may change in a minor release of the panel's API, so the addon opts in
 *   to each; cms:build refuses a contribution to one it does not list, as
 *   registry_panel_experimental_not_accepted.
 * - contributions: what the addon contributes, each a PanelContribution. The list is the
 *   allowance: what it names is exactly what the addon may touch, and the install screen shows it.
 * - themes: the addon's themes of token values (theme.v1.json), each by a local name of lower-case
 *   words joined by hyphens and the absolute path of its JSON file. The installation selects one
 *   as `<namespace>:<name>` in cbox-cms.panel.themes; a theme it does not select has no effect.
 *   Shipping one needs AddonCapabilities::$uiTheme, or cms:build refuses the manifest as
 *   registry_panel_theme_invalid. A theme sets tokens only, so it can never change the
 *   installation's name, logo or favicon (cbox-cms.panel.branding).
 */
#[Experimental]
final readonly class PanelContributions
{
    public ?string $bundle;

    /** @var list<string> */
    public array $acceptsExperimental;

    /** The form of a theme's local name. */
    public const string THEME_NAME_PATTERN = '/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/';

    /** The longest local name of a theme. */
    public const int THEME_NAME_MAX_LENGTH = 40;

    /** @var array<string, string> the theme files by local name, sorted by name */
    public array $themes;

    /**
     * @param  string|null  $bundle  the absolute directory of the addon's bundle, built from __DIR__
     * @param  list<string>  $acceptsExperimental  point ids, such as "account.me.sections@1"
     * @param  list<PanelContribution>  $contributions
     * @param  array<string, string>  $themes  absolute theme files by local name, built from __DIR__
     *
     * @throws InvalidAddonManifest when the bundle directory is not absolute, a point is accepted twice, or a theme's name or file is not of its form
     */
    public function __construct(
        public PanelApiVersion $sdk,
        ?string $bundle = null,
        array $acceptsExperimental = [],
        public array $contributions = [],
        array $themes = [],
    ) {
        $this->bundle = $bundle === null ? null : ClassNames::absoluteDirectory('panel bundle', $bundle);

        foreach (array_count_values($acceptsExperimental) as $point => $times) {
            if ($times > 1) {
                throw InvalidAddonManifest::because(sprintf('The panel contributions accept the experimental point "%s" %d times. List each once.', $point, $times));
            }
        }

        sort($acceptsExperimental, SORT_STRING);
        $this->acceptsExperimental = $acceptsExperimental;

        foreach ($themes as $name => $file) {
            $name = (string) $name;

            if (preg_match(self::THEME_NAME_PATTERN, $name) !== 1 || strlen($name) > self::THEME_NAME_MAX_LENGTH) {
                throw InvalidAddonManifest::because(sprintf('The panel theme name "%s" is not lower-case words and digits joined by hyphens, at most %d characters, such as "brand".', $name, self::THEME_NAME_MAX_LENGTH));
            }

            if (! str_ends_with($file, '.json') || (! str_starts_with($file, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $file) !== 1)) {
                throw InvalidAddonManifest::because(sprintf('The panel theme "%s" names the file "%s", which is not the absolute path of a .json file. Build it from __DIR__ in the service provider.', $name, $file));
            }
        }

        ksort($themes, SORT_STRING);
        $this->themes = $themes;
    }

    /**
     * Whether the addon opts in to the experimental point with the id.
     */
    public function accepts(PointId $point): bool
    {
        return in_array($point->toString(), $this->acceptsExperimental, true);
    }
}
