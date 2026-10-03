<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\ContributionOverride;
use Cbox\Cms\Core\Registry\Domain\Dto\ReplacementChoice;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads the installation's settings cms:build compiles (PRD 13.8, 13.4):
 *
 *     'addons' => ['allowed' => ['acme/cms-approvals']],
 *     'panel' => [
 *         'contributions' => [
 *             'account.me.sections@1' => ['approvals.badge' => ['priority' => 50, 'enabled' => true]],
 *         ],
 *         'replacements' => [
 *             'command.form.field@1' => ['acme:stars' => 'acme.stars-input'],
 *         ],
 *     ],
 *
 * A value of the wrong form is a problem of the build, not an exception: the allowlist's as
 * registry_addon_not_allowed and the panel's as registry_panel_override_invalid, each naming the
 * setting.
 */
#[Internal]
final readonly class BuildSettingsConfig
{
    public const string ALLOWED = 'cbox-cms.addons.allowed';

    public const string CONTRIBUTIONS = 'cbox-cms.panel.contributions';

    public const string REPLACEMENTS = 'cbox-cms.panel.replacements';

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

        return new BuildSettings($allowed, $overrides, $replacements, $problems);
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
