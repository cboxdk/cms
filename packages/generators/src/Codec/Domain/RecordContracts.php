<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\Dto\StringForm;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;

/**
 * The record contract of a type (PRD 8.9): every field of the type, compiled from its descriptor
 * into the codec model, so the DTO and the codec follow the descriptor's types and rules and share
 * the validators' rule set (PRD 11.12).
 *
 * The JSON object of a record has the entry's id under `cms_id`, each of the owner's fields under
 * its handle, and the extension fields under `ext`, by the namespace of their extender and then by
 * handle, as the code addresses them (`ext.app.tax_code`). A group is an object of its fields, or a
 * list of them when it repeats. The root class is `<Owner><Handle>V1`, as the type's case of the
 * TypeHandle enum with the version; a group's class adds the group's handle in TitleCase to the
 * class it is in, and the extension classes add `Ext` and the namespace.
 */
#[Internal]
final readonly class RecordContracts
{
    /** The contract version of the records. */
    public const int VERSION = 1;

    /** The key of the entry's id; `cms_` is never a handle. */
    public const string ID_KEY = 'cms_id';

    /**
     * The form of the entry's id, a UUIDv7 (PRD 5.3), in the dialect of JSON Schema, as the kernel's
     * schemas write an id; EntryId checks it in PHP, and the TypeScript validator checks this.
     */
    public const string ID_PATTERN = '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$';

    /**
     * What the record holds for each core field type of the blueprint schema v1. A field type
     * without a mapping here is refused as invalid output. The generator-coverage test holds the
     * keys to the field types of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array FIELD_TYPES = [
        'boolean' => 'bool, a JSON boolean',
        'date' => 'DateTimeImmutable, YYYY-MM-DD',
        'datetime' => 'DateTimeImmutable, RFC 3339 in UTC with six decimals',
        'decimal' => 'numeric-string with the field\'s scale, a JSON string',
        'group' => 'a DTO of its fields, or a list of them when it repeats',
        'integer' => 'int, a JSON integer',
        'long_text' => 'string',
        'rich_text' => 'ListValue of Portable Text blocks',
        'select' => 'one of the values, or a list of them with multiple',
        'text' => 'string',
    ];

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput for a field type without a mapping
     */
    public static function of(TypeDescriptor $type): CodecContract
    {
        $name = PhpTypeHandleEnum::caseName($type);
        $className = $name.'V'.self::VERSION;
        $generated = self::generatedBy();
        $properties = [new CodecProperty(self::ID_KEY, 'cmsId', CodecValue::id(EntryId::class, new StringForm(self::ID_PATTERN)), true, null, 'The id of the entry (PRD 5.3).')];

        foreach ($type->ownFields() as $field) {
            $properties[] = self::property($field, $className, $type, true);
        }

        $extensions = $type->extensionFields();

        if ($extensions !== []) {
            $properties[] = new CodecProperty(
                'ext',
                'ext',
                CodecValue::object(self::extensions($extensions, $className, $type)),
                true,
                null,
                'The fields extensions add, by the namespace of their extender (PRD 11.12).',
            );
        }

        $root = new CodecObject($className, [
            sprintf('The record of %s in contract version %d (PRD 8.9): every field of the type.', $type->name(), self::VERSION),
            ...self::about($type->label, $type->description),
            '',
            ...$generated,
        ], $properties);

        return new CodecContract($root, $name.'CodecV'.self::VERSION, self::VERSION, [
            sprintf('The JSON codec of the record of %s, contract version %d (GUARDRAILS 2.2).', $type->name(), self::VERSION),
            '',
            ...$generated,
        ]);
    }

    /**
     * The name of a handle or a namespace in camelCase, for a property.
     */
    public static function propertyName(string $handle): string
    {
        return lcfirst(self::className($handle));
    }

    /**
     * The name of a handle or a namespace in TitleCase, for a class.
     */
    public static function className(string $handle): string
    {
        return str_replace('_', '', ucwords($handle, '_'));
    }

    /**
     * @param  array<string, list<FieldDescriptor>>  $extensions
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function extensions(array $extensions, string $className, TypeDescriptor $type): CodecObject
    {
        $namespaces = [];

        foreach ($extensions as $namespace => $fields) {
            $namespaceClass = $className.'Ext'.self::className($namespace);
            $namespaces[] = new CodecProperty(
                $namespace,
                self::propertyName($namespace),
                CodecValue::object(new CodecObject($namespaceClass, [
                    sprintf('The fields that %s adds to %s (PRD 11.12).', $namespace, $type->name()),
                    '',
                    ...self::generatedBy(),
                ], array_map(static fn (FieldDescriptor $field): CodecProperty => self::property($field, $namespaceClass, $type, true), $fields))),
                true,
                null,
                sprintf('The fields of the namespace %s.', $namespace),
            );
        }

        return new CodecObject($className.'Ext', [
            sprintf('The extension fields of %s, by the namespace of their extender (PRD 11.12).', $type->name()),
            '',
            ...self::generatedBy(),
        ], $namespaces);
    }

    /**
     * @param  bool  $topLevel  whether the field is a column of the type table, which has its own classification
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function property(FieldDescriptor $field, string $parentClass, TypeDescriptor $type, bool $topLevel): CodecProperty
    {
        $rules = $field->validation;
        $presence = array_shift($rules);

        return new CodecProperty(
            $field->handle->value,
            self::propertyName($field->handle->value),
            self::value($field, $rules, $parentClass, $type),
            $presence instanceof ValidationRule && $presence->name === ValidationRuleName::Required,
            $topLevel ? ClassificationAccess::from($field->classification->value) : null,
            implode(' ', self::about($field->label, $field->description)),
        );
    }

    /**
     * @param  list<ValidationRule>  $rules  the field's rules without `required` and `nullable`
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function value(FieldDescriptor $field, array $rules, string $parentClass, TypeDescriptor $type): CodecValue
    {
        if (! array_key_exists($field->type, self::FIELD_TYPES)) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf(
                '%s has no mapping for the field type "%s" of %s. Add the field type to its FIELD_TYPES.',
                self::class,
                $field->type,
                $field->location->describe(),
            ));
        }

        $list = self::has($rules, ValidationRuleName::List);

        return match ($field->type) {
            'text', 'long_text' => CodecValue::of(CodecKind::Text, $rules),
            'integer' => CodecValue::of(CodecKind::Integer, $rules),
            'decimal' => CodecValue::of(CodecKind::Decimal, $rules),
            'boolean' => CodecValue::of(CodecKind::Boolean, $rules),
            'date' => CodecValue::of(CodecKind::Date, $rules),
            'datetime' => CodecValue::of(CodecKind::Datetime, $rules),
            'rich_text' => CodecValue::of(CodecKind::PortableText, $rules),
            'select' => $list
                ? CodecValue::list(
                    CodecValue::of(CodecKind::Choice, [new ValidationRule(ValidationRuleName::In, self::arguments($rules, ValidationRuleName::ItemsIn))]),
                    self::without($rules, ValidationRuleName::ItemsIn),
                )
                : CodecValue::of(CodecKind::Choice, $rules),
            default => self::group($field, $rules, $parentClass, $type, $list),
        };
    }

    /**
     * @param  list<ValidationRule>  $rules
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function group(FieldDescriptor $field, array $rules, string $parentClass, TypeDescriptor $type, bool $list): CodecValue
    {
        $className = $parentClass.self::className($field->handle->value);
        $object = new CodecObject($className, [
            sprintf('The group %s of %s%s.', $field->handle->value, $type->name(), $list ? ', one item of it' : ''),
            ...self::about($field->label, $field->description),
            '',
            ...self::generatedBy(),
        ], array_map(static fn (FieldDescriptor $inner): CodecProperty => self::property($inner, $className, $type, false), $field->fields));

        return $list
            ? CodecValue::list(CodecValue::object($object), $rules)
            : CodecValue::object($object, $rules);
    }

    /**
     * @param  list<ValidationRule>  $rules
     */
    private static function has(array $rules, ValidationRuleName $name): bool
    {
        return array_any($rules, static fn (ValidationRule $rule): bool => $rule->name === $name);
    }

    /**
     * @param  list<ValidationRule>  $rules
     * @return list<string>
     */
    private static function arguments(array $rules, ValidationRuleName $name): array
    {
        foreach ($rules as $rule) {
            if ($rule->name === $name) {
                return $rule->arguments;
            }
        }

        return [];
    }

    /**
     * @param  list<ValidationRule>  $rules
     * @return list<ValidationRule>
     */
    private static function without(array $rules, ValidationRuleName $name): array
    {
        return array_values(array_filter($rules, static fn (ValidationRule $rule): bool => $rule->name !== $name));
    }

    /**
     * The label and the description on one line, such as `Title: The title of the article.`
     *
     * @return list<string>
     */
    private static function about(string $label, ?string $description): array
    {
        return [PhpSource::docText($description === null ? $label.'.' : $label.': '.$description)];
    }

    /**
     * @return list<string>
     */
    private static function generatedBy(): array
    {
        return [
            'Generated by cms:generate from the blueprint v1 files below the schema roots. Do not edit',
            'this file: change the blueprints and run cms:generate.',
        ];
    }
}
