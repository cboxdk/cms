<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\ClassNames;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Checks the values a contribution to a panel point holds, as the classes that implement
 * PanelContribution build them. Each refusal is InvalidAddonManifest, which cms:build reports as
 * registry_invalid_manifest, naming the service provider.
 */
#[Internal]
final readonly class ContributionRules
{
    public const string ICON_PATTERN = '/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/';

    public const int ICON_MAX_LENGTH = 64;

    /**
     * @throws InvalidAddonManifest
     */
    public static function priority(string $contribution, int $priority): int
    {
        if ($priority < 0 || $priority > PanelContribution::MAX_PRIORITY) {
            throw InvalidAddonManifest::because(sprintf('The contribution %s has the priority %d. Give a priority from 0 to %d; the core\'s own contributions are at 100, 200 and so on.', $contribution, $priority, PanelContribution::MAX_PRIORITY));
        }

        return $priority;
    }

    /**
     * @throws InvalidAddonManifest
     */
    public static function translationKey(string $contribution, string $what, string $key): string
    {
        if (preg_match(PanelPoint::LABEL_PATTERN, $key) !== 1) {
            throw InvalidAddonManifest::because(sprintf('The %s of the contribution %s, "%s", is not a translation key: dot-separated segments of lowercase letters, digits, hyphens and underscores, such as "approvals.request.label".', $what, $contribution, $key));
        }

        return $key;
    }

    /**
     * @throws InvalidAddonManifest
     */
    public static function icon(string $contribution, ?string $icon): ?string
    {
        if ($icon !== null && (strlen($icon) > self::ICON_MAX_LENGTH || preg_match(self::ICON_PATTERN, $icon) !== 1)) {
            throw InvalidAddonManifest::because(sprintf('The icon of the contribution %s, "%s", is not the name of a kit icon: lowercase letters and digits with single hyphens, such as "key".', $contribution, $icon));
        }

        return $icon;
    }

    /**
     * The class name without a leading backslash.
     *
     * @throws InvalidAddonManifest
     */
    public static function className(string $contribution, string $what, string $class): string
    {
        return ClassNames::check(sprintf('the %s of the contribution %s', $what, $contribution), $class);
    }
}
