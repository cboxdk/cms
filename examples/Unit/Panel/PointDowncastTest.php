<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\PointDowncasts;
use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Panel\Reviews\ReviewsServiceProvider;
use Examples\Unit\Panel\Reviews\ReviewSubmitV1;
use Examples\Unit\Panel\Reviews\ReviewSubmitV2;
use PHPUnit\Framework\Attributes\Test;

/**
 * The review form's submit button has two versions. The panel builds the props of the newest,
 * and a contribution to version 1 gets its props through version 1's downcast.
 */
final class PointDowncastTest extends BuildTestCase
{
    #[Test]
    public function it_builds_the_props_of_an_older_version_from_the_newest_versions(): void
    {
        self::assertSame(0, $this->build(ReviewsServiceProvider::class));

        $downcasts = new PointDowncasts(app(CompiledRegistry::class));
        $newest = new ReviewSubmitV2('review.create', 1);

        self::assertEquals(new ReviewSubmitV1('review.create'), $downcasts->props(PointId::fromString('reviews.form.submit@1'), $newest));
        self::assertSame($newest, $downcasts->props(PointId::fromString('reviews.form.submit@2'), $newest));
    }
}
