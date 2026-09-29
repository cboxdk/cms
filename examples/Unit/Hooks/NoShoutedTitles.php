<?php

declare(strict_types=1);

namespace Examples\Unit\Hooks;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;

/**
 * Adds an error for a title written in capitals only, a rule the blueprint cannot express. The
 * kernel adds it after its own errors as validation_hook_failed at fields.title.
 */
#[Hook(command: PublishStory::class, phase: Phase::Validate, priority: 0, budgetMs: 1)]
final readonly class NoShoutedTitles implements ValidateHook
{
    public function validate(PlanView $plan): HookErrors
    {
        $errors = [];

        foreach ($plan->revisions() as $revision) {
            $title = $revision->fields->own->get(new FieldHandle('title'));

            if ($title instanceof TextValue && preg_match('/[a-z]/', $title->value) !== 1 && preg_match('/[A-Z]{2}/', $title->value) === 1) {
                $errors[] = HookError::onField(new FieldHandle('title'), 'Write the title in sentence case, not in capitals.');
            }
        }

        return new HookErrors(...$errors);
    }
}
