<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Panel\Reviews\ReviewSectionsV1;
use Examples\Unit\Panel\Reviews\ReviewsServiceProvider;
use Examples\Unit\Panel\Reviews\ReviewSubmitV1;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * cms:build writes the panel points of the reviews package to panel.php under their ids, and
 * cms:panel:points and cms:panel:fills read them back.
 */
final class PanelPointsTest extends BuildTestCase
{
    #[Test]
    public function it_registers_a_panel_point_under_its_id(): void
    {
        self::assertSame(0, $this->build(ReviewsServiceProvider::class));

        $panel = require $this->registryFile('panel');
        self::assertIsArray($panel);
        self::assertSame('panel', $panel['registry']);
        self::assertIsArray($panel['entries']);
        self::assertContains([
            'class' => ReviewSectionsV1::class,
            'fills' => [],
            'id' => 'reviews.detail.sections@1',
            'keyed_by' => null,
            'kind' => 'slot',
            'label' => 'reviews.points.detail_sections',
            'max' => null,
            'multiplicity' => 'many',
            'ownership' => null,
            'package' => 'acme/cms-reviews',
            'page' => 'reviews.detail',
            'region' => 'sections',
            'since' => '1.0',
            'stability' => 'experimental',
            'tightens' => [],
        ], $panel['entries']);

        // Every point of the package is there, each under its own id.
        $points = array_column($panel['entries'], 'class', 'id');
        self::assertSame(ReviewSubmitV1::class, $points['reviews.form.submit@1'] ?? null);
    }

    #[Test]
    public function it_lists_the_points_of_a_page_and_the_contributions_to_a_point(): void
    {
        self::assertSame(0, $this->build(ReviewsServiceProvider::class));

        self::assertSame(0, app(Kernel::class)->call('cms:panel:points', ['selector' => 'reviews.form']));
        self::assertStringContainsString(
            'reviews.form.submit@1  decorator, renders many, tightens disabled_reason and description and tone_towards_danger  page reviews.form  experimental since 1.0  0 contributions',
            app(Kernel::class)->output(),
        );

        // No addon contributes to the point yet, so the panel renders none.
        self::assertSame(0, app(Kernel::class)->call('cms:panel:fills', ['point' => 'reviews.detail.sections@1']));
        self::assertSame("reviews.detail.sections@1: no contributions.\n", app(Kernel::class)->output());

        // A point the registry does not hold is a usage error.
        self::assertSame(64, app(Kernel::class)->call('cms:panel:fills', ['point' => 'reviews.detail.sections@2']));
    }
}
