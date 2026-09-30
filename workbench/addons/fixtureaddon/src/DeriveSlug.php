<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;

/**
 * The fixture addon's transform hook on entry.create (PRD 6.3, 11.12): derives the addon's field
 * ext.fixtureaddon.fixture_slug of every revision of app:fixture_article the plan writes from the
 * owner's fixture_title, when the revision sets no slug of its own. It is deterministic and does no
 * IO; the kernel validates the slug with the rest of the fields after it. A later entry.revise
 * writes every field again, so it keeps a slug only when the caller sends it.
 *
 * The slug is the title in lower case with every run of other characters than a to z and 0 to 9
 * as one hyphen, without hyphens at its ends, and at most 120 characters, as the blueprint allows.
 * A revision without a title, or one whose title has no letter or digit, gets no slug.
 */
#[Hook(command: CreateEntry::class, phase: Phase::Transform, priority: 10, budgetMs: 2)]
final readonly class DeriveSlug implements TransformHook
{
    /** The longest slug, the blueprint's max_length. */
    public const int MAX_LENGTH = 120;

    public function transform(PlanView $plan): FieldChanges
    {
        $changes = [];

        foreach ($plan->revisions() as $revision) {
            $slug = $this->slugFor($revision);

            if ($slug instanceof TextValue) {
                $changes[] = FieldChange::extension(new VariantRef($revision->entry, $revision->variant), FixtureArticle::namespace(), FixtureArticle::slug(), $slug);
            }
        }

        return new FieldChanges(...$changes);
    }

    /**
     * The slug of a title: null when it has no letter or digit.
     */
    public static function slugOf(string $title): ?string
    {
        $slug = trim(substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-'), 0, self::MAX_LENGTH), '-');

        return $slug === '' ? null : $slug;
    }

    private function slugFor(RevisionCreated $revision): ?TextValue
    {
        if (! FixtureArticle::is($revision->type) || $this->isSet($revision->fields->extension(FixtureArticle::namespace())?->get(FixtureArticle::slug()))) {
            return null;
        }

        $title = $revision->fields->own->get(FixtureArticle::title());
        $slug = $title instanceof TextValue ? self::slugOf($title->value) : null;

        return $slug === null ? null : new TextValue($slug);
    }

    private function isSet(?FieldValue $value): bool
    {
        return $value instanceof FieldValue && ! $value instanceof NullValue;
    }
}
