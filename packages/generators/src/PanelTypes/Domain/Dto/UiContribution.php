<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;

/**
 * One contribution of an addon that runs code, as cms:build compiled it (PRD 13.4): its id, its
 * kind, the point it contributes to with its props, the result of its data query for a slot fill
 * or a page, the command document of a form check or a flow step, the props a decorator tightens,
 * by Tighten's values, the paths a flow step patches, a form check's severity and a flow step's
 * position.
 */
#[Internal]
final readonly class UiContribution
{
    /**
     * @param  list<string>  $tightens
     * @param  list<string>  $patches
     */
    public function __construct(
        public ContributionId $id,
        public PointKind $kind,
        public PointType $point,
        public ?ContractShape $data = null,
        public ?ContractShape $command = null,
        public array $tightens = [],
        public array $patches = [],
        public ?Severity $severity = null,
        public ?StepPosition $position = null,
    ) {}
}
