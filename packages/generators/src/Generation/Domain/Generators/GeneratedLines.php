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
 * What the M0 generators write alike: the schema roots in the header, and the field type of a
 * field from a generator's own mapping.
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
     * The value a generator writes for the core field type of a field, from its mapping.
     *
     * @param  class-string  $generator
     * @param  array<string, string>  $mapping  core field type to the value written
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput when the mapping lacks the type
     */
    public static function fieldType(string $generator, array $mapping, FieldDescriptor $field): string
    {
        $type = $field->type;

        if (! array_key_exists($type, $mapping)) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf(
                '%s has no mapping for the field type "%s" of %s. Add the field type to its FIELD_TYPES.',
                $generator,
                $type,
                $field->location->describe(),
            ));
        }

        return $mapping[$type];
    }
}
