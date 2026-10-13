<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\Layout;
use Cbox\Cms\Generators\Schema\Domain\Dto\LayoutField;
use Cbox\Cms\Generators\Schema\Domain\Dto\LayoutSection;

/**
 * The rules of a blueprint's `layout` (gap G2), which JSON Schema cannot express because they
 * compare the layout with the fields of its file: a type's layout places every top-level field of
 * the type exactly once, and an extension's fragment every field of its file, so a form never
 * loses a field and never shows one twice. Each rule has its own code and names the placement, or
 * the layout, that breaks it:
 *
 * - a placement names a field the file does not have at the top level, such as a field inside a
 *   group, which its group's input holds, or another owner's field (generate_layout_field_unknown);
 * - a placement names a field an earlier placement already placed (generate_layout_field_repeated);
 * - the layout leaves a top-level field out (generate_layout_field_omitted, at the layout);
 * - a placement's `width` is not a preset (generate_layout_width_unknown);
 * - two tabs, or two sections of one tab or of the sidebar, share a handle
 *   (generate_layout_duplicate_handle).
 */
#[Internal]
final readonly class LayoutRules
{
    /**
     * @param  list<FieldBlueprint>  $fields  the top-level fields of the layout's file
     * @param  string  $what  the file as a problem names it, such as "the type article"
     * @return list<GenerationProblem>
     */
    public static function problems(Layout $layout, array $fields, string $what): array
    {
        $problems = [];
        $tabs = [];

        foreach ($layout->tabs as $tab) {
            if (isset($tabs[$tab->handle->value])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::LayoutDuplicateHandle, sprintf(
                    '%s: the tab %s is already the tab at %s. The tabs of a layout need different handles.',
                    $tab->location->below('handle')->describe(),
                    $tab->handle->value,
                    $tabs[$tab->handle->value]->describe(),
                ));
            } else {
                $tabs[$tab->handle->value] = $tab->location;
            }

            self::sections($tab->sections, 'the tab '.$tab->handle->value, $problems);
        }

        self::sections($layout->sidebar, 'the sidebar', $problems);

        /** @var array<string, FieldBlueprint> $known */
        $known = [];

        foreach ($fields as $field) {
            $known[$field->handle->value] = $field;
        }

        /** @var array<string, LayoutField> $placed */
        $placed = [];

        foreach ($layout->placements() as $placement) {
            if (! isset($known[$placement->field])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::LayoutFieldUnknown, sprintf(
                    '%s: %s has no top-level field %s. A layout places the top-level fields of its own file, never a field inside a group or another owner\'s field.',
                    $placement->location->below('field')->describe(),
                    $what,
                    $placement->field,
                ));
            } elseif (isset($placed[$placement->field])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::LayoutFieldRepeated, sprintf(
                    '%s: the field %s is already placed at %s. A layout places every field exactly once.',
                    $placement->location->below('field')->describe(),
                    $placement->field,
                    $placed[$placement->field]->location->describe(),
                ));
            } else {
                $placed[$placement->field] = $placement;
            }

            if (! $placement->width() instanceof LayoutWidth) {
                $problems[] = new GenerationProblem(GenerateErrorCode::LayoutWidthUnknown, sprintf(
                    '%s: the width %s is not a preset. The presets are %s.',
                    $placement->location->below('width')->describe(),
                    $placement->width,
                    LayoutWidth::names(),
                ));
            }
        }

        foreach ($known as $handle => $field) {
            if (! isset($placed[$handle])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::LayoutFieldOmitted, sprintf(
                    '%s: the layout leaves out the field %s at %s. A layout places every top-level field of %s exactly once: add it to a section, or remove the layout.',
                    $layout->location->describe(),
                    $handle,
                    $field->location->describe(),
                    $what,
                ));
            }
        }

        return $problems;
    }

    /**
     * @param  list<LayoutSection>  $sections
     * @param  list<GenerationProblem>  $problems
     */
    private static function sections(array $sections, string $where, array &$problems): void
    {
        $handles = [];

        foreach ($sections as $section) {
            if (isset($handles[$section->handle->value])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::LayoutDuplicateHandle, sprintf(
                    '%s: the section %s is already the section at %s. The sections of %s need different handles.',
                    $section->location->below('handle')->describe(),
                    $section->handle->value,
                    $handles[$section->handle->value]->describe(),
                    $where,
                ));
            } else {
                $handles[$section->handle->value] = $section->location;
            }
        }
    }
}
