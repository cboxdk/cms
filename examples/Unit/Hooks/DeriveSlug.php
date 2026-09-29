<?php

declare(strict_types=1);

namespace Examples\Unit\Hooks;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;

/**
 * Derives the slug of every revision the plan writes from its title, when the revision sets no
 * slug. It is deterministic and does no IO; the kernel validates the slug with the rest of the
 * fields after it.
 */
#[Hook(command: PublishStory::class, phase: Phase::Transform, priority: 10, budgetMs: 2)]
final readonly class DeriveSlug implements TransformHook
{
    public function transform(PlanView $plan): FieldChanges
    {
        $changes = [];

        foreach ($plan->revisions() as $revision) {
            $title = $revision->fields->own->get(new FieldHandle('title'));

            if ($title instanceof TextValue && $revision->fields->own->get(new FieldHandle('slug')) === null) {
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title->value)), '-');
                $changes[] = FieldChange::own(new VariantRef($revision->entry, $revision->variant), new FieldHandle('slug'), new TextValue($slug));
            }
        }

        return new FieldChanges(...$changes);
    }
}
