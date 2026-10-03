<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * An entry of the panel's navigation (PRD 13.4): a link to one of the addon's own pages, shown
 * under the label and icon. It is data and runs no code of the addon. The host shows it only to
 * a viewer who holds the permission its scope requires.
 *
 * - page: the id of a PageContribution of the same addon. cms:build refuses a nav entry whose
 *   page the addon does not contribute, as registry_panel_nav_target_unknown.
 *
 * The id, point, priority and scope are those of every contribution (PanelContribution).
 */
#[Experimental]
final readonly class NavContribution implements PanelContribution
{
    public int $priority;

    public string $label;

    public ?string $icon;

    /**
     * @param  string  $label  the translation key of its text
     * @param  string  $page  the id of a PageContribution of the addon
     * @param  string|null  $icon  a kit icon, such as "inbox"
     *
     * @throws InvalidAddonManifest when a value breaks its rule
     */
    public function __construct(
        public ContributionId $id,
        public string $point,
        string $label,
        public string $page,
        ?string $icon = null,
        int $priority = self::DEFAULT_PRIORITY,
        public Scope $scope = new Scope,
    ) {
        $this->priority = ContributionRules::priority($id->value, $priority);
        $this->label = ContributionRules::translationKey($id->value, 'label', $label);
        $this->icon = ContributionRules::icon($id->value, $icon);
    }

    public function id(): ContributionId
    {
        return $this->id;
    }

    public function point(): string
    {
        return $this->point;
    }

    public function kind(): PointKind
    {
        return PointKind::Nav;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function runsCode(): bool
    {
        return false;
    }
}
