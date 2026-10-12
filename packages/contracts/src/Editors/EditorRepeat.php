<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Editors;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How a repeated group repeats (PRD 11.12): the fewest and the most items the form lets an editor
 * add, as the blueprint's `repeat` declares them, each null when the blueprint leaves it out. The
 * form builds the group's `list` rule from them.
 */
#[Experimental]
final readonly class EditorRepeat
{
    /**
     * @throws InvalidTypeEditor when the bounds contradict each other
     */
    public function __construct(
        public ?int $minItems,
        public ?int $maxItems,
    ) {
        if (($minItems ?? 0) < 0 || ($maxItems ?? 1) < 1 || ($minItems ?? 0) > ($maxItems ?? PHP_INT_MAX)) {
            throw InvalidTypeEditor::items($minItems ?? 0, $maxItems ?? 1);
        }
    }
}
