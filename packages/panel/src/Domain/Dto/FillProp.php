<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;

/**
 * One active contribution as a panel page sends it (contributions.v1.json, `#/$defs/fill`): its
 * id, its addon, its kind, the priority it renders at, the point's props as the point's codec
 * wrote them for it, and whether its data comes as the deferred prop ext.<addon>.
 */
#[Internal]
final readonly class FillProp
{
    public function __construct(
        public AddonNamespace $addon,
        public bool $data,
        public ContributionId $id,
        public PointKind $kind,
        public int $priority,
        public JsonDocument $props,
    ) {}
}
