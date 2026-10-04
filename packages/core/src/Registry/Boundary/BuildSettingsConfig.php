<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeSelection;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\ContributionOverride;
use Cbox\Cms\Core\Registry\Domain\Dto\ReplacementChoice;
use Cbox\Cms\Core\Registry\Domain\Dto\SignaturePolicy;
use Cbox\Cms\Core\Registry\Domain\PublisherKey;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Reads the installation's settings cms:build compiles (PRD 13.8, 13.4):
 *
 *     'addons' => [
 *         'allowed' => ['acme/cms-approvals'],
 *         'publishers' => ['acme/cms-approvals' => ['<base64 Ed25519 public key>']],
 *     ],
 *     'panel' => [
 *         'contributions' => [
 *             'account.me.sections@1' => ['approvals.badge' => ['priority' => 50, 'enabled' => true]],
 *         ],
 *         'replacements' => [
 *             'command.form.field@1' => ['acme:stars' => 'acme.stars-input'],
 *         ],
 *         'themes' => ['fixtureaddon:brand', 'app'],
 *         'app_theme' => base_path('resources/panel/theme.json'),
 *     ],
 *
 * A value of the wrong form is a problem of the build, not an exception: the allowlist's as
 * registry_addon_not_allowed, the publishers' as registry_panel_bundle_unsigned, the panel's
 * overrides as registry_panel_override_invalid and its themes as registry_panel_theme_invalid,
 * each naming the setting. The environment, app.env, decides whether a bundle of an addon the
 * installation trusts no key for passes unsigned: only local does (decision D8).
 */
#[Internal]
final readonly class BuildSettingsConfig
{
    public const string ALLOWED = 'cbox-cms.addons.allowed';

    public const string PUBLISHERS = 'cbox-cms.addons.publishers';

    public const string ENVIRONMENT = 'app.env';

    /** The one environment that accepts an unsigned bundle of an addon without trusted keys. */
    public const string LOCAL = 'local';

    public const string CONTRIBUTIONS = 'cbox-cms.panel.contributions';

    public const string REPLACEMENTS = 'cbox-cms.panel.replacements';

    public const string THEMES = 'cbox-cms.panel.themes';

    public const string APP_THEME = 'cbox-cms.panel.app_theme';

    public static function read(Repository $config): BuildSettings
    {
        $problems = [];
        $allowed = self::allowed($config->get(self::ALLOWED, []), $problems);
        $overrides = [];
        $replacements = [];
        $contributions = $config->get(self::CONTRIBUTIONS, []);
        $choices = $config->get(self::REPLACEMENTS, []);

        foreach (self::map($contributions, self::CONTRIBUTIONS, 'a map from point ids to maps from contribution ids to settings', $problems) as $point => $settings) {
            $pointId = self::point((string) $point, self::CONTRIBUTIONS, $problems);

            foreach (self::map($settings, self::CONTRIBUTIONS.'.'.$point, 'a map from contribution ids to settings', $problems) as $id => $setting) {
                $at = sprintf('%s.%s.%s', self::CONTRIBUTIONS, $point, $id);
                $contribution = self::contribution((string) $id, $at, $problems);
                $values = self::map($setting, $at, 'a map with priority, a whole number, and enabled, a boolean', $problems);
                $unknown = array_diff(array_map(strval(...), array_keys($values)), ['enabled', 'priority']);
                $priority = $values['priority'] ?? null;
                $enabled = $values['enabled'] ?? null;

                if ($unknown !== [] || ($priority !== null && ! is_int($priority)) || ($enabled !== null && ! is_bool($enabled))) {
                    $problems[] = self::problem(BuildErrorCode::PanelOverrideInvalid, sprintf('The setting %s takes only priority, a whole number, and enabled, a boolean.', $at));

                    continue;
                }

                if ($pointId instanceof PointId && $contribution instanceof ContributionId) {
                    $overrides[] = new ContributionOverride($pointId, $contribution, $priority, $enabled);
                }
            }
        }

        foreach (self::map($choices, self::REPLACEMENTS, 'a map from point ids to maps from keys to contribution ids', $problems) as $point => $keys) {
            $pointId = self::point((string) $point, self::REPLACEMENTS, $problems);

            foreach (self::map($keys, self::REPLACEMENTS.'.'.$point, 'a map from keys to the contribution ids that win them', $problems) as $key => $winner) {
                $at = sprintf('%s.%s.%s', self::REPLACEMENTS, $point, $key);
                $contribution = is_string($winner) ? self::contribution($winner, $at, $problems) : null;

                if (! is_string($winner)) {
                    $problems[] = self::problem(BuildErrorCode::PanelOverrideInvalid, sprintf('The setting %s must be the id of the replacement that wins the key; it is %s.', $at, get_debug_type($winner)));
                }

                if ($pointId instanceof PointId && $contribution instanceof ContributionId) {
                    $replacements[] = new ReplacementChoice($pointId, (string) $key, $contribution);
                }
            }
        }

        $themes = self::themes($config->get(self::THEMES, []), $config->get(self::APP_THEME), $problems);
        $signatures = new SignaturePolicy(self::publishers($config->get(self::PUBLISHERS, []), $problems), $config->get(self::ENVIRONMENT) === self::LOCAL);

        return new BuildSettings($allowed, $overrides, $replacements, $problems, $themes, $signatures);
    }

    /**
     * @param  list<BuildProblem>  $problems
     * @return array<string, list<PublisherKey>>
     */
    private static function publishers(mixed $value, array &$problems): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $problems[] = self::problem(BuildErrorCode::PanelBundleUnsigned, sprintf('The setting %s must be a map from the Composer packages of the addons to lists of their publishers\' Ed25519 public keys, each the base64 of its 32 bytes; it is %s.', self::PUBLISHERS, get_debug_type($value)));

            return [];
        }

        $publishers = [];

        foreach ($value as $package => $keys) {
            $at = sprintf('%s.%s', self::PUBLISHERS, $package);

            if (! is_string($package) || preg_match(ScanRoot::PACKAGE_PATTERN, $package) !== 1) {
                $problems[] = self::problem(BuildErrorCode::PanelBundleUnsigned, sprintf('The setting %s names "%s", which is not a Composer package name such as "acme/cms-approvals".', self::PUBLISHERS, $package));

                continue;
            }

            if (! is_array($keys) || ! array_is_list($keys)) {
                $problems[] = self::problem(BuildErrorCode::PanelBundleUnsigned, sprintf('The setting %s must be a list of the publisher\'s Ed25519 public keys, each the base64 of its 32 bytes; it is %s.', $at, get_debug_type($keys)));

                continue;
            }

            $trusted = [];

            foreach ($keys as $key) {
                try {
                    $trusted[] = new PublisherKey(is_string($key) ? $key : throw new InvalidArgumentException(sprintf('%s is not an Ed25519 public key.', get_debug_type($key))));
                } catch (InvalidArgumentException $invalid) {
                    $problems[] = self::problem(BuildErrorCode::PanelBundleUnsigned, sprintf('The setting %s names a key it cannot use. %s', $at, $invalid->getMessage()));
                }
            }

            $publishers[$package] = $trusted;
        }

        return $publishers;
    }

    /**
     * @param  list<BuildProblem>  $problems
     */
    private static function themes(mixed $selected, mixed $appTheme, array &$problems): ThemeSelection
    {
        $themes = [];

        if ($selected !== null && (! is_array($selected) || ! array_is_list($selected))) {
            $problems[] = self::problem(BuildErrorCode::PanelThemeInvalid, sprintf('The setting %s must be a list of theme names in the order they compose, such as ["fixtureaddon:brand", "app"]; it is %s.', self::THEMES, get_debug_type($selected)));
            $selected = [];
        }

        foreach ($selected ?? [] as $name) {
            try {
                $themes[] = new ThemeName(is_string($name) ? $name : throw new InvalidArgumentException(sprintf('%s is not the name of a panel theme.', get_debug_type($name))));
            } catch (InvalidArgumentException $invalid) {
                $problems[] = self::problem(BuildErrorCode::PanelThemeInvalid, sprintf('The setting %s names a theme it cannot use. %s', self::THEMES, $invalid->getMessage()));
            }
        }

        if ($appTheme !== null && (! is_string($appTheme) || ! str_ends_with($appTheme, '.json') || (! str_starts_with($appTheme, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $appTheme) !== 1))) {
            $problems[] = self::problem(BuildErrorCode::PanelThemeInvalid, sprintf('The setting %s must be the absolute path of the application\'s theme JSON, such as base_path(\'resources/panel/theme.json\'), or null; it is %s.', self::APP_THEME, is_string($appTheme) ? '"'.$appTheme.'"' : get_debug_type($appTheme)));
            $appTheme = null;
        }

        return new ThemeSelection($themes, is_string($appTheme) ? $appTheme : null);
    }

    /**
     * @param  list<BuildProblem>  $problems
     * @return list<string>
     */
    private static function allowed(mixed $value, array &$problems): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $problems[] = self::problem(BuildErrorCode::AddonNotAllowed, sprintf('The setting %s must be a list of the Composer packages of the allowed addons; it is %s.', self::ALLOWED, get_debug_type($value)));

            return [];
        }

        $allowed = [];

        foreach ($value as $package) {
            if (! is_string($package) || preg_match(ScanRoot::PACKAGE_PATTERN, $package) !== 1) {
                $problems[] = self::problem(BuildErrorCode::AddonNotAllowed, sprintf('The setting %s names %s, which is not a Composer package name such as "acme/cms-approvals".', self::ALLOWED, is_string($package) ? '"'.$package.'"' : get_debug_type($package)));

                continue;
            }

            $allowed[] = $package;
        }

        return $allowed;
    }

    /**
     * @param  list<BuildProblem>  $problems
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value, string $at, string $form, array &$problems): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $problems[] = self::problem(BuildErrorCode::PanelOverrideInvalid, sprintf('The setting %s must be %s; it is %s.', $at, $form, get_debug_type($value)));

            return [];
        }

        return $value;
    }

    /**
     * @param  list<BuildProblem>  $problems
     */
    private static function point(string $text, string $at, array &$problems): ?PointId
    {
        try {
            return PointId::fromString($text);
        } catch (InvalidPanelPoint $invalid) {
            $problems[] = self::problem(BuildErrorCode::PanelOverrideInvalid, sprintf('The setting %s names "%s". %s', $at, $text, $invalid->getMessage()));

            return null;
        }
    }

    /**
     * @param  list<BuildProblem>  $problems
     */
    private static function contribution(string $text, string $at, array &$problems): ?ContributionId
    {
        try {
            return new ContributionId($text);
        } catch (InvalidPanelPoint $invalid) {
            $problems[] = self::problem(BuildErrorCode::PanelOverrideInvalid, sprintf('The setting %s names "%s". %s', $at, $text, $invalid->getMessage()));

            return null;
        }
    }

    private static function problem(BuildErrorCode $code, string $message): BuildProblem
    {
        return new BuildProblem($code, $message);
    }
}
