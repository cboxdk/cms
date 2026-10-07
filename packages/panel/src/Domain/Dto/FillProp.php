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
 * wrote them for it, or null for a point whose props the page holds in the browser, whether its
 * data comes as the deferred prop ext.<addon>, and what its kind
 * needs besides: an action's command and how it is shown, a check's command and severity, a
 * step's command, position, paths and timeout, what a decorator may tighten, the key a
 * replacement replaces, or a nav entry's text, icon and page. Each of those is null for a
 * contribution of another kind.
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
        public ?JsonDocument $props,
        public ?ActionProp $action = null,
        public ?CheckProp $check = null,
        public ?DecoratorProp $decorator = null,
        public ?ReplacementProp $replacement = null,
        public ?StepProp $step = null,
        public ?NavProp $nav = null,
    ) {}
}
