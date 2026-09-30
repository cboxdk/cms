<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Workbench\FixtureAddon\DeriveSlug;
use Workbench\FixtureAddon\FixtureArticle;

/**
 * The fixture addon's transform hook on entry.create: it derives ext.fixtureaddon.fixture_slug
 * from the owner's title for every revision of app:fixture_article without a slug, and changes
 * nothing else.
 */
final class DeriveSlugTest extends TestCase
{
    #[Test]
    public function it_derives_the_slug_of_each_article_from_its_title(): void
    {
        $views = new PlanViews;
        $one = $views->entry();
        $two = $views->entry();

        $changes = new DeriveSlug()->transform($views->create(
            $views->revision($one, PlanViews::article(), PlanViews::fields('A quiet week, mostly')),
            $views->revision($two, PlanViews::article(), PlanViews::fields('  Ünïcode & Co. 2026!  ')),
        ))->changes;

        self::assertEquals([
            FieldChange::extension(new VariantRef($one, VariantKey::shared()), FixtureArticle::namespace(), FixtureArticle::slug(), new TextValue('a-quiet-week-mostly')),
            FieldChange::extension(new VariantRef($two, VariantKey::shared()), FixtureArticle::namespace(), FixtureArticle::slug(), new TextValue('n-code-co-2026')),
        ], $changes);
    }

    #[Test]
    public function it_keeps_a_slug_the_revision_sets_and_derives_none_without_a_title(): void
    {
        $views = new PlanViews;

        $changes = new DeriveSlug()->transform($views->create(
            $views->revision($views->entry(), PlanViews::article(), PlanViews::fields('A quiet week', 'chosen')),
            $views->revision($views->entry(), PlanViews::article(), PlanViews::fields(null)),
            $views->revision($views->entry(), PlanViews::article(), PlanViews::fields('!!!')),
        ));

        self::assertTrue($changes->isEmpty());
    }

    #[Test]
    public function it_leaves_the_revisions_of_other_types_alone(): void
    {
        $views = new PlanViews;

        self::assertTrue(new DeriveSlug()->transform($views->create(
            $views->revision($views->entry(), $views->otherType(), PlanViews::fields('A quiet week')),
        ))->isEmpty());
    }

    #[Test]
    public function it_cuts_a_slug_to_the_length_the_blueprint_allows(): void
    {
        $slug = DeriveSlug::slugOf(str_repeat('word ', 30));

        self::assertNotNull($slug);
        self::assertSame(DeriveSlug::MAX_LENGTH - 1, strlen($slug));
        self::assertStringEndsWith('d', $slug);
        self::assertNull(DeriveSlug::slugOf(' - '));
    }
}
