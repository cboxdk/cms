<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Reviews;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Tighten;

/**
 * The props of the submit button of the review form, version 2: the command and its version as
 * two members, a breaking change to version 1's props, which keeps working through its downcast.
 */
#[Experimental]
#[PanelPoint(
    name: 'reviews.form.submit',
    version: 2,
    kind: PointKind::Decorator,
    page: 'reviews.form',
    since: '1.1',
    label: 'reviews.points.form_submit',
    tightens: [Tighten::DisabledReason, Tighten::Description, Tighten::ToneTowardsDanger],
)]
final readonly class ReviewSubmitV2
{
    public function __construct(
        public string $command,
        public int $commandVersion,
    ) {}
}
