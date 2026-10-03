<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The id of one contribution to the panel, `<namespace>.<local>`, such as `approvals.badge` or
 * `fixtureaddon.slug-hint`: the namespace of the addon that contributes it (`cms` for the core's
 * own), then one or more dot-separated segments of lowercase letters, digits, single hyphens and
 * underscores, each starting with a letter, at most 96 characters in all. Ids are unique in the
 * installation because the namespace is.
 */
#[Experimental]
final readonly class ContributionId
{
    public const string PATTERN = '/\A(?<namespace>[a-z][a-z0-9]{0,19})(?:\.[a-z][a-z0-9_]*(?:-[a-z0-9_]+)*)+\z/';

    public const int MAX_LENGTH = 96;

    /**
     * @throws InvalidPanelPoint
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidPanelPoint::because(sprintf(
                'The contribution id "%s" is not the addon\'s namespace and one or more dot-separated segments of lowercase letters, digits, hyphens and underscores, of at most %d characters, such as "approvals.badge".',
                $value,
                self::MAX_LENGTH,
            ));
        }
    }

    /**
     * The namespace of the addon that contributes it, the part before the first dot.
     */
    public function namespace(): AddonNamespace
    {
        return new AddonNamespace(explode('.', $this->value, 2)[0]);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
