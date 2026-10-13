<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedCatalog;

/**
 * Which types of the catalog a seeder can write (GUARDRAILS 2.4): every type with a generated
 * validator whose required fields the actor may write. A field above the actor's classification
 * access or stored encrypted cannot be written, so a type that requires one is left out, with the
 * reason. The types are sorted by name, which orders the seed profile's type mix. The seeder
 * writes the shared variant of every entry and, for a type with localization variants, a variant
 * per language the sites publish in (PRD 10.1), so such a type is seeded through the rules of its
 * shared fields and the rules of a language's fields (TypeRules::forVariant()), and is left out
 * when the seeder reaches no site locale, because an entry of it needs a language.
 */
#[Internal]
final readonly class SeedTypes
{
    /** A language, so forVariant() gives the rules of a language's variant; which language is immaterial. */
    private const string ANY_LANGUAGE = 'und';

    /**
     * @param  list<TypeDefinition>  $types
     * @param  bool  $languages  whether the seeder reaches a site locale to seed a language in
     */
    public static function of(array $types, TypeValidators $validators, ClassificationAccess $access, bool $languages = true): SeedCatalog
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

            $localized = $type->capabilities->localization === Localization::Variants;
            $rules = $validator->rules()->forVariant(VariantKey::shared(), $type->capabilities->localization);
            $languageRules = $localized ? $validator->rules()->forVariant(VariantKey::of(new Locale(self::ANY_LANGUAGE)), $type->capabilities->localization) : null;
            $blocked = self::blockedIn($type, $rules, $access);

            if ($languageRules instanceof TypeRules) {
                array_push($blocked, ...self::blockedIn($type, $languageRules, $access));
            }

            if ($blocked !== []) {
                $skipped[] = sprintf('The type %s requires %s, which the seeding actor cannot write: a field above its classification access %s, or stored encrypted.', $type->name->value, implode(', ', $blocked), $access->value);

                continue;
            }

            if ($localized && ! $languages) {
                $skipped[] = sprintf('The type %s has localization variants, so an entry of it needs a language, and the seeding actor reaches no site locale to seed one in.', $type->name->value);

                continue;
            }

            $seedable[] = new SeedableType(
                $type,
                $rules,
                $type->capabilities->stages === Stages::DraftRelease && $type->capabilities->history === History::Full,
                $languageRules,
            );
        }

        return new SeedCatalog($seedable, $skipped);
    }

    /**
     * The addresses of the required fields of a variant's rules the actor cannot write.
     *
     * @return list<string>
     */
    private static function blockedIn(TypeDefinition $type, TypeRules $rules, ClassificationAccess $access): array
    {
        $blocked = self::blocked($type, null, $rules->fields, $access);

        foreach ($rules->extensions as $extension) {
            array_push($blocked, ...self::blocked($type, $extension->namespace, $extension->fields, $access));
        }

        return $blocked;
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
