<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;

/**
 * The fixture addon's validate hook on entry.create (PRD 6.3, 13.4): a slug a caller sets by hand
 * in ext.fixtureaddon.fixture_slug of a revision of app:fixture_article must be well formed, SHAPE:
 * lowercase letters and digits in runs joined by single hyphens, as DeriveSlug derives one from
 * the title. A revision without a slug passes, because DeriveSlug gives it one before this hook
 * runs, and its length is the blueprint's rule, not this one's. The kernel adds the error as
 * validation_hook_failed at the field and rejects the create with validation_failed.
 *
 * The addon's form check fixtureaddon.slug-shape in the panel mirrors it (the mirror rule, PRD
 * 13.4): it blocks the submit of entry.create's form on the same documents, and
 * resources/panel/parity/slug-shape.json holds both to the same verdicts.
 */
#[Hook(command: CreateEntry::class, phase: Phase::Validate, priority: 20, budgetMs: 2)]
final readonly class RequireWellFormedSlug implements ValidateHook
{
    /** A well-formed slug: runs of lowercase letters and digits joined by single hyphens. */
    public const string SHAPE = '/\A[a-z0-9]+(-[a-z0-9]+)*\z/';

    public function validate(PlanView $plan): HookErrors
    {
        $errors = [];

        foreach ($plan->revisions() as $revision) {
            $slug = $revision->fields->extension(FixtureArticle::namespace())?->get(FixtureArticle::slug());

            if (FixtureArticle::is($revision->type) && $slug instanceof TextValue && ! self::isWellFormed($slug->value)) {
                $errors[] = HookError::onField(
                    FixtureArticle::slug(),
                    sprintf('The slug "%s" is not well formed: use lowercase letters and digits joined by single hyphens, such as a-quiet-week.', $slug->value),
                    FixtureArticle::namespace(),
                );
            }
        }

        return new HookErrors(...$errors);
    }

    public static function isWellFormed(string $slug): bool
    {
        return preg_match(self::SHAPE, $slug) === 1;
    }
}
