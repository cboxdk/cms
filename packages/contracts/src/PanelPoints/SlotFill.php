<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A contribution to a slot (PRD 13.4): a component of the addon's bundle, registered under the
 * contribution's id, that the host renders at the slot with the point's props, in its own error
 * boundary.
 *
 * - data: a #[Query] of the addon, or null. The panel runs it as the viewer through the query
 *   pipeline, at the lower of the viewer's classification access and the addon's reads
 *   capability, with the query's input taken from the point's props by name, and hands its
 *   result to the component. cms:build refuses a query that is not the addon's or whose input
 *   the props cannot give, as registry_panel_data_query_invalid.
 *
 * The id, point, priority and scope are those of every contribution (PanelContribution).
 */
#[Experimental]
final readonly class SlotFill implements PanelContribution
{
    public int $priority;

    public ?string $data;

    /**
     * @param  string|null  $data  the class of a #[Query] of the addon, such as PendingApprovalsFor::class
     *
     * @throws InvalidAddonManifest when a value breaks its rule
     */
    public function __construct(
        public ContributionId $id,
        public string $point,
        ?string $data = null,
        int $priority = self::DEFAULT_PRIORITY,
        public Scope $scope = new Scope,
    ) {
        $this->priority = ContributionRules::priority($id->value, $priority);
        $this->data = $data === null ? null : ContributionRules::className($id->value, 'data query', $data);
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
        return PointKind::Slot;
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
        return true;
    }
}
