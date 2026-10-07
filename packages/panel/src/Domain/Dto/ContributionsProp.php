<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * The prop cms.contributions of a panel page behind the login (PRD 13.4), contributions.v1.json,
 * which the panel's host renders the page's points from: each point the page renders that has an
 * active contribution, with those contributions in render order; the addons they come from, each
 * with the digest its code's registration must match and the commands it may issue; whether the
 * viewer sees the detail of a contribution that failed; the pages a contribution may navigate to;
 * the address the host runs commands through; and the viewer's actor id, which a form check's
 * context names. Written by its generated codec, ContributionsCodecV1.
 */
#[Internal]
final readonly class ContributionsProp
{
    /**
     * @param  list<PointFillsProp>  $points
     * @param  list<AddonProp>  $addons
     * @param  list<PageLinkProp>  $pages
     */
    public function __construct(
        public array $points,
        public array $addons,
        public string $commands,
        public bool $details,
        public array $pages,
        public ?ActorId $viewer,
    ) {}
}
