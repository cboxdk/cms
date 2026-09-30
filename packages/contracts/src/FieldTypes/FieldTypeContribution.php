<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A field type an addon contributes, such as `reviews:stars` (PRD 13.1, 11.12). A blueprint file
 * names it as a field's `type` and writes its choices under `options`, so they never collide with a
 * key that blueprint v1 adds to every field.
 *
 * - name() is the field type's name in the addon's namespace.
 * - optionsSchema() is the absolute path of a JSON Schema (draft 2020-12) file that `options` must
 *   satisfy. cms:generate checks every field of the type against it, and reports each violation as
 *   generate_schema_invalid at its JSON pointer; a field without `options` is checked as `{}`.
 * - shape() says what every generator writes for a field of the type with the options: the core
 *   field type whose form its value takes and that type's options, as a FieldShape. The descriptor,
 *   the DDL of the type table, the PHP record, the query builder, the DTO and its codec, the
 *   validators and the TypeScript are then those of that shape, and the kernel reads and writes the
 *   value in that form, while the field keeps the addon's type name in the generated code and the
 *   type catalog.
 */
#[Experimental]
interface FieldTypeContribution
{
    public function name(): ContributedFieldType;

    /**
     * The absolute path of the JSON Schema of the type's `options`, built from __DIR__.
     */
    public function optionsSchema(): string;

    /**
     * What the generators write for a field of the type with the options, which the options schema
     * has accepted.
     *
     * @throws InvalidFieldTypeOptions|InvalidFieldShape when the options cannot be made into a shape;
     *                                                   cms:generate reports the message at the field's options
     */
    public function shape(FieldTypeOptions $options): FieldShape;
}
