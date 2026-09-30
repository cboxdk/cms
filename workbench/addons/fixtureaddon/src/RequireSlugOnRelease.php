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
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;

/**
 * The fixture addon's validate hook on variant.release (PRD 6.3, 11.12, invariant 36): a revision
 * of app:fixture_article is released only with the addon's field ext.fixtureaddon.fixture_slug.
 * The field is optional in the blueprint, because the owner's code creates and revises entries
 * without knowing it, so the addon requires it here, at the release, and never on entry.create or
 * entry.revise. The kernel adds the error as validation_hook_failed at the field and rejects the
 * release with validation_failed.
 */
#[Hook(command: ReleaseVariant::class, phase: Phase::Validate, priority: 10, budgetMs: 2)]
final readonly class RequireSlugOnRelease implements ValidateHook
{
    public function validate(PlanView $plan): HookErrors
    {
        $errors = [];

        foreach ($plan->releases() as $released) {
            $slug = $released->fields->extension(FixtureArticle::namespace())?->get(FixtureArticle::slug());

            if (FixtureArticle::is($released->release->type) && (! $slug instanceof TextValue || $slug->value === '')) {
                $errors[] = HookError::onField(
                    FixtureArticle::slug(),
                    sprintf('Revision %d has no slug; save it with ext.fixtureaddon.fixture_slug before it is released.', $released->release->revision->value),
                    FixtureArticle::namespace(),
                );
            }
        }

        return new HookErrors(...$errors);
    }
}
