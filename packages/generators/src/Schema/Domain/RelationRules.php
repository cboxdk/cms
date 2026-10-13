<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\RelationOptions;

/**
 * The rule of a relation field (PRD 5.11) that JSON Schema cannot express: every type its `types`
 * names, `<owner>:<handle>`, is a type a blueprint file defines, for the relation fields of every
 * type and extension and the fields nested in them (generate_unknown_relation_type). A type may
 * name itself, and a type of another owner.
 *
 * A file that could not be read may define the type, so the rule holds only when every file was
 * read, as an unknown `extends` does.
 */
#[Internal]
final readonly class RelationRules
{
    /**
     * @param  bool  $complete  whether every blueprint file below the schema roots was read
     * @return list<GenerationProblem>
     */
    public static function problems(Blueprints $blueprints, bool $complete): array
    {
        if (! $complete) {
            return [];
        }

        $known = [];

        foreach ($blueprints->types as $type) {
            $known[$type->owner->value.':'.$type->handle->value] = true;
        }

        $problems = [];

        foreach ($blueprints->types as $type) {
            self::fields($type->fields, $known, $problems);
        }

        foreach ($blueprints->extensions as $extension) {
            self::fields($extension->fields, $known, $problems);
        }

        return $problems;
    }

    /**
     * @param  list<FieldBlueprint>  $fields
     * @param  array<string, true>  $known  the names of the types the blueprints define
     * @param  list<GenerationProblem>  $problems
     */
    private static function fields(array $fields, array $known, array &$problems): void
    {
        foreach ($fields as $field) {
            self::fields($field->options->nestedFields(), $known, $problems);

            if (! $field->options instanceof RelationOptions) {
                continue;
            }

            foreach ($field->options->typeNames() as $index => $name) {
                if (! isset($known[$name])) {
                    $problems[] = new GenerationProblem(GenerateErrorCode::UnknownRelationType, sprintf(
                        '%s: no blueprint file below the schema roots defines the type %s, so the relation %s cannot hold its entries. Name a type <owner>:<handle> that a blueprint defines, such as %s, or add the schema root of its owner.',
                        $field->location->below('types', $index)->describe(),
                        $name,
                        $field->handle->value,
                        $known === [] ? 'app:article' : array_key_first($known),
                    ));
                }
            }
        }
    }
}
