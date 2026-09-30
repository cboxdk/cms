<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What the generators write for a field of an addon's field type (FieldTypeContribution::shape()): a core
 * field type and its options. The shapes are TextShape, LongTextShape, IntegerShape, DecimalShape,
 * BooleanShape, DateShape, DatetimeShape and SelectShape, one per case of FieldBase; cms:generate
 * refuses any other implementation with generate_invalid_config.
 */
#[Experimental]
interface FieldShape
{
    /**
     * The core field type whose form a value of the field takes.
     */
    public function base(): FieldBase;
}
