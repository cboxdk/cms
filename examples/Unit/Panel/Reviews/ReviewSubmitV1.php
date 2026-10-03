<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Reviews;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\DowncastsFromNewest;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Tighten;

/**
 * The props of the submit button of the review form, which decorators may tighten: disable it with
 * a reason, append to its description, or move its tone towards danger. Version 2 is the newest, so
 * this version builds its props from version 2's through its downcast.
 *
 * @implements DowncastsFromNewest<ReviewSubmitV2>
 */
#[Experimental]
#[PanelPoint(
    name: 'reviews.form.submit',
    version: 1,
    kind: PointKind::Decorator,
    page: 'reviews.form',
    since: '1.0',
    label: 'reviews.points.form_submit',
    tightens: [Tighten::DisabledReason, Tighten::Description, Tighten::ToneTowardsDanger],
)]
final readonly class ReviewSubmitV1 implements DowncastsFromNewest
{
    public function __construct(public string $command) {}

    public static function downcast(object $newest): static
    {
        return new self($newest->command);
    }
}
