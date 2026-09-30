<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;

/**
 * What the generators write alike: the schema roots in the header, and the field type of a field
 * from a generator's own mapping. A generator maps the core field types, and writes a field of an
 * addon's field type as it writes the core field type the field takes the form of, its base
 * (FieldDescriptor::$base), so every generator writes every contributed field type.
 */
#[Internal]
final readonly class GeneratedLines
{
    /**
     * One line per schema root, sorted by owner: `app: schema`. The directories are relative, so
     * the output names no machine-specific path.
     *
     * @return list<string>
     */
    public static function schemaRoots(GenerationTarget $target, string $prefix): array
    {
        return array_map(
            static fn (SchemaRoot $root): string => $prefix.$root->owner->value.': '.$root->directory,
            $target->roots,
        );
    }

    /**
     * The value a generator writes for the core field type of a field, its base, from its mapping.
     *
     * @param  class-string  $generator
     * @param  array<string, string>  $mapping  core field type to the value written
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput when the mapping lacks the base
     */
    public static function fieldType(string $generator, array $mapping, FieldDescriptor $field): string
    {
        $base = $field->base;

        if (! array_key_exists($base, $mapping)) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf(
                '%s has no mapping for the field type "%s"%s of %s. Add the field type to its FIELD_TYPES.',
                $generator,
                $base,
                $base === $field->type ? '' : sprintf(', the base of the field type "%s",', $field->type),
                $field->location->describe(),
            ));
        }

        return $mapping[$base];
    }

    /**
     * The field type's name, such as `text` or `reviews:stars`, for a generator that writes the
     * name, once its mapping has the field's base.
     *
     * @param  class-string  $generator
     * @param  array<string, string>  $mapping  core field type to the value written
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput when the mapping lacks the base
     */
    public static function typeName(string $generator, array $mapping, FieldDescriptor $field): string
    {
        self::fieldType($generator, $mapping, $field);

        return $field->type;
    }
}
