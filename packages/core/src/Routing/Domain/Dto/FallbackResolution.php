<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldValues;

/**
 * What the fallback of PRD 10.3 gives a resolution (LocaleFallback): the step that explains it,
 * and the fields the page shows, those of the resolved locale's released row with the fields that
 * fell back in place, or null when no locale tried has a released row.
 */
#[Internal]
final readonly class FallbackResolution
{
    public function __construct(
        public FallbackStep $step,
        public ?FieldValues $fields,
    ) {}
}
