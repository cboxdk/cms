<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The prop cms.contributions of a panel page behind the login (PRD 13.4), contributions.v1.json:
 * each point the page renders that has an active contribution, with those contributions in render
 * order. Written by its generated codec, ContributionsCodecV1.
 */
#[Internal]
final readonly class ContributionsProp
{
    /**
     * @param  list<PointFillsProp>  $points
     */
    public function __construct(public array $points) {}
}
