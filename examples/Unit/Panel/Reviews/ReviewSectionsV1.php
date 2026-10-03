<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Reviews;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * The props of the sections of a review's page, a slot other addons fill with sections of their
 * own. The class is the point's props, and #[Experimental] its stability.
 */
#[Experimental]
#[PanelPoint(
    name: 'reviews.detail.sections',
    version: 1,
    kind: PointKind::Slot,
    page: 'reviews.detail',
    since: '1.0',
    label: 'reviews.points.detail_sections',
    region: Region::Sections,
)]
final readonly class ReviewSectionsV1
{
    public function __construct(
        public string $review,
        public int $stars,
    ) {}
}
