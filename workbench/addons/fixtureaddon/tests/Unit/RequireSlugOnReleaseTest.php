<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Hooks\HookError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Workbench\FixtureAddon\FixtureArticle;
use Workbench\FixtureAddon\RequireSlugOnRelease;

/**
 * The fixture addon's validate hook on variant.release: a revision of app:fixture_article is
 * released only with ext.fixtureaddon.fixture_slug (invariant 36).
 */
final class RequireSlugOnReleaseTest extends TestCase
{
    #[Test]
    public function it_requires_the_slug_of_a_released_article(): void
    {
        $views = new PlanViews;

        $errors = new RequireSlugOnRelease()->validate($views->release($views->entry(), PlanViews::article(), 3, PlanViews::fields('A quiet week')))->errors;

        self::assertEquals([HookError::onField(
            FixtureArticle::slug(),
            'Revision 3 has no slug; save it with ext.fixtureaddon.fixture_slug before it is released.',
            FixtureArticle::namespace(),
        )], $errors);
    }

    #[Test]
    public function it_releases_an_article_with_a_slug_and_other_types_without_one(): void
    {
        $views = new PlanViews;
        $hook = new RequireSlugOnRelease;

        self::assertTrue($hook->validate($views->release($views->entry(), PlanViews::article(), 1, PlanViews::fields(null, 'a-quiet-week')))->isEmpty());
        self::assertTrue($hook->validate($views->release($views->entry(), $views->otherType(), 1, PlanViews::fields('A quiet week')))->isEmpty());
    }

    #[Test]
    public function it_has_nothing_to_say_about_a_plan_that_releases_nothing(): void
    {
        $views = new PlanViews;

        self::assertTrue(new RequireSlugOnRelease()->validate($views->create(
            $views->revision($views->entry(), PlanViews::article(), PlanViews::fields('A quiet week')),
        ))->isEmpty());
    }
}
