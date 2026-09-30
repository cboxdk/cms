<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedCatalog;

/**
 * Which types of the catalog a seeder can write (GUARDRAILS 2.4): every type with a generated
 * validator whose required fields the actor may write. A field above the actor's classification
 * access or stored encrypted cannot be written, so a type that requires one is left out, with the
 * reason. The types are sorted by name, which orders the seed profile's type mix.
 */
#[Internal]
final readonly class SeedTypes
{
    /**
     * @param  list<TypeDefinition>  $types
     */
    public static function of(array $types, TypeValidators $validators, ClassificationAccess $access): SeedCatalog
    {
        usort($types, static fn (TypeDefinition $one, TypeDefinition $other): int => strcmp($one->name->value, $other->name->value));

        $seedable = [];
        $skipped = [];

        foreach ($types as $type) {
            $validator = $validators->find($type->id);

            if (! $validator instanceof TypeValidator) {
                $skipped[] = sprintf('The type %s has no generated validator; run cms:generate.', $type->name->value);

                continue;
            }

            $rules = $validator->rules();
            $blocked = self::blocked($type, null, $rules->fields, $access);

            foreach ($rules->extensions as $extension) {
                array_push($blocked, ...self::blocked($type, $extension->namespace, $extension->fields, $access));
            }

            if ($blocked !== []) {
                $skipped[] = sprintf('The type %s requires %s, which the seeding actor cannot write: a field above its classification access %s, or stored encrypted.', $type->name->value, implode(', ', $blocked), $access->value);

                continue;
            }

            $seedable[] = new SeedableType(
                $type,
                $rules,
                $type->capabilities->stages === Stages::DraftRelease && $type->capabilities->history === History::Full,
            );
        }

        return new SeedCatalog($seedable, $skipped);
    }

    /**
     * The addresses of the required fields the actor cannot write.
     *
     * @param  list<FieldRules>  $rules
     * @return list<string>
     */
    private static function blocked(TypeDefinition $type, ?FieldNamespace $namespace, array $rules, ClassificationAccess $access): array
    {
        $blocked = [];

        foreach ($rules as $field) {
            $definition = $type->field($namespace, $field->handle);

            if (FieldValueGenerator::required($field) && (! $definition instanceof FieldDefinition || ! FieldValueGenerator::writable($definition, $access))) {
                $blocked[] = $definition instanceof FieldDefinition ? $definition->address() : $field->handle->value;
            }
        }

        return $blocked;
    }
}
