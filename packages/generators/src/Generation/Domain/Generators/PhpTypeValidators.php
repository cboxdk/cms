<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generator;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Override;

/**
 * The runtime validators of the type chain (PRD 11.8, 11.12 "Hvor statiske typer slutter"): a
 * class per type in the directory Validators below the PHP directory, named after the type's
 * TypeHandle case, such as `Validators/ShopProductValidator.php` for `shop:product`. Each
 * implements Cbox\Cms\Contracts\Validation\TypeValidator and declares the rules of the type's
 * fields from its descriptor, so input from outside meets the same rules as the type table and the
 * records; the kernel's InputValidator checks input against them.
 *
 * The descriptor's `required` and `nullable` become the field's Presence: a field whose validator
 * starts with `required` is Required. An extension field is never, because the owner's code writes
 * entries without knowing it; one its blueprint marks required is RequiredOnRelease (PRD 11.12
 * point 1, invariant 36). The other rules keep their names and arguments. The owner's fields come
 * first, sorted by handle, then each extender's under its namespace, sorted by namespace and handle;
 * a group's nested fields are sorted by handle.
 */
#[Internal]
final readonly class PhpTypeValidators implements Generator
{
    /** The directory below the PHP directory, and the segment of the namespace, of the validators. */
    public const string DIRECTORY = 'Validators';

    /**
     * What the class docblock lists for a field of each core field type: its name. A field type
     * without a mapping here is refused as invalid output. The generator-coverage test holds the
     * keys to the field types of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array FIELD_TYPES = [
        'boolean' => 'boolean',
        'date' => 'date',
        'datetime' => 'datetime',
        'decimal' => 'decimal',
        'group' => 'group',
        'integer' => 'integer',
        'long_text' => 'long_text',
        'rich_text' => 'rich_text',
        'select' => 'select',
        'text' => 'text',
    ];

    /**
     * What the validators hold for a blueprint file of each kind. The generator-coverage test holds
     * the keys to the kinds of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array KINDS = [
        'extension' => 'the rules of its fields in the validator of the type it extends, under its namespace',
        'type' => 'a validator with the rules of the type\'s own fields',
    ];

    private const string INDENT = '    ';

    #[Override]
    public function directory(GenerationTarget $target): string
    {
        return $target->phpDirectory;
    }

    #[Override]
    public function generate(CompiledSchema $schema, GenerationTarget $target): array
    {
        return array_map(fn (TypeDescriptor $type): GeneratedFile => $this->file($type, $target), $schema->types);
    }

    /**
     * The class name of the validator of the type: its TypeHandle case and `Validator`.
     */
    public static function className(TypeDescriptor $type): string
    {
        return PhpTypeHandleEnum::caseName($type).'Validator';
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function file(TypeDescriptor $type, GenerationTarget $target): GeneratedFile
    {
        $extensions = $type->extensionFields();
        $fields = [];

        foreach ($type->ownFields() as $field) {
            array_push($fields, ...$this->field($field, $this->presence($field, true), 4));
        }

        $arguments = [$this->line(3, '['), ...$fields, $this->line(3, '],')];

        if ($extensions !== []) {
            $arguments[] = $this->line(3, '[');

            foreach ($extensions as $namespace => $extensionFields) {
                $arguments[] = $this->line(4, 'new ExtensionRules(new FieldNamespace('.$this->literal($namespace).'), [');

                foreach ($extensionFields as $field) {
                    array_push($arguments, ...$this->field($field, $this->presence($field, true), 5));
                }

                $arguments[] = $this->line(4, ']),');
            }

            $arguments[] = $this->line(3, '],');
        }

        $uses = [
            FieldHandle::class,
            ...($extensions === [] ? [] : [FieldNamespace::class, ExtensionRules::class]),
            TypeId::class,
            FieldRules::class,
            Presence::class,
            Rule::class,
            RuleName::class,
            TypeRules::class,
            TypeValidator::class,
            'Override',
        ];
        sort($uses, SORT_STRING);

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace '.$target->phpNamespace.'\\'.self::DIRECTORY.';',
            '',
            ...array_map(static fn (string $class): string => 'use '.$class.';', $uses),
            '',
            '/**',
            sprintf(' * The runtime validator of the type %s, version %d (PRD 11.8, 11.12).', $type->name(), $type->version),
            ' *',
            " * It declares the rules of the type's fields for input from outside the repository, which",
            ' * Cbox\\Cms\\Core\\Validation\\Boundary\\InputValidator checks.',
            ' *',
            ' * Generated by cms:generate from the blueprint v1 files below the schema roots. Do not edit',
            ' * this file: change the blueprints and run cms:generate.',
            ' *',
            ' * Fields, as the input holds them:',
            ...$this->fieldList($type),
            ' */',
            'final readonly class '.self::className($type).' implements TypeValidator',
            '{',
            $this->line(1, '#[Override]'),
            $this->line(1, 'public function type(): TypeId'),
            $this->line(1, '{'),
            $this->line(2, 'return TypeId::fromString('.$this->literal($type->typeId->toString()).');'),
            $this->line(1, '}'),
            '',
            $this->line(1, '#[Override]'),
            $this->line(1, 'public function rules(): TypeRules'),
            $this->line(1, '{'),
            $this->line(2, 'return new TypeRules('),
            ...$arguments,
            $this->line(2, ');'),
            $this->line(1, '}'),
            '}',
        ];

        return new GeneratedFile(
            $target->phpDirectory.'/'.self::DIRECTORY.'/'.self::className($type).'.php',
            implode("\n", $lines)."\n",
        );
    }

    /**
     * One docblock line per field and nested field, with its address, its field type and whether
     * it needs a value. The nested fields of a repeated group are addressed with `[]`, one item.
     *
     * @return list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function fieldList(TypeDescriptor $type): array
    {
        $lines = [];

        foreach ($type->ownFields() as $field) {
            array_push($lines, ...$this->fieldLines($field, '', $this->presence($field, true)));
        }

        foreach ($type->extensionFields() as $namespace => $fields) {
            foreach ($fields as $field) {
                array_push($lines, ...$this->fieldLines($field, 'ext.'.$namespace.'.', $this->presence($field, true)));
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private function fieldLines(FieldDescriptor $field, string $prefix, Presence $presence): array
    {
        $address = $prefix.$field->handle->value;
        $lines = [sprintf(' *   %s: %s, %s', $address, GeneratedLines::fieldType(self::class, self::FIELD_TYPES, $field), match ($presence) {
            Presence::Required => 'required',
            Presence::RequiredOnRelease => 'required on release',
            Presence::Optional => 'optional',
        })];

        $repeated = array_any($field->validation, static fn (ValidationRule $rule): bool => $rule->name === ValidationRuleName::List);

        foreach ($field->fields as $nested) {
            array_push($lines, ...$this->fieldLines($nested, $address.($repeated ? '[].' : '.'), $this->presence($nested, false)));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function field(FieldDescriptor $field, Presence $presence, int $depth): array
    {
        $lines = [
            $this->line($depth, 'new FieldRules('),
            $this->line($depth + 1, 'new FieldHandle('.$this->literal($field->handle->value).'),'),
            $this->line($depth + 1, 'Presence::'.$presence->name.','),
            $this->line($depth + 1, '['),
        ];

        foreach (self::rules($field) as $rule) {
            $lines[] = $this->line($depth + 2, $this->rule($rule).',');
        }

        $lines[] = $this->line($depth + 1, '],');

        if ($field->fields !== []) {
            $lines[] = $this->line($depth + 1, '[');

            foreach ($field->fields as $nested) {
                array_push($lines, ...$this->field($nested, $this->presence($nested, false), $depth + 2));
            }

            $lines[] = $this->line($depth + 1, '],');
        }

        $lines[] = $this->line($depth, '),');

        return $lines;
    }

    private function rule(ValidationRule $rule): string
    {
        $name = self::ruleName($rule->name)->name ?? '';

        if ($rule->arguments === []) {
            return 'new Rule(RuleName::'.$name.')';
        }

        return sprintf('new Rule(RuleName::%s, [%s])', $name, implode(', ', array_map($this->literal(...), $rule->arguments)));
    }

    /**
     * The field's rules in the kernel's vocabulary, without `required` and `nullable`. A field's
     * rules start with a type rule there; a select field of one choice has only `in` in the
     * descriptor, which holds text, so its rules start with `string`.
     *
     * @return list<ValidationRule>
     */
    public static function rules(FieldDescriptor $field): array
    {
        $rules = array_values(array_filter($field->validation, static fn (ValidationRule $rule): bool => self::ruleName($rule->name) instanceof RuleName));
        $first = $rules[0] ?? null;

        if ($first instanceof ValidationRule && $first->name === ValidationRuleName::In) {
            array_unshift($rules, new ValidationRule(ValidationRuleName::String));
        }

        return $rules;
    }

    /**
     * The field's Presence: Required when its validator starts with `required`; for a top-level
     * extension field that its blueprint marks required, RequiredOnRelease; otherwise Optional.
     */
    private function presence(FieldDescriptor $field, bool $topLevel): Presence
    {
        if (($field->validation[0] ?? null)?->name === ValidationRuleName::Required) {
            return Presence::Required;
        }

        return $topLevel && $field->namespace instanceof Owner && $field->required ? Presence::RequiredOnRelease : Presence::Optional;
    }

    /**
     * The rule of the kernel's validators for a rule of the descriptor, or null for `required` and
     * `nullable`, which are the field's Presence.
     */
    public static function ruleName(ValidationRuleName $name): ?RuleName
    {
        return match ($name) {
            ValidationRuleName::Required, ValidationRuleName::Nullable => null,
            ValidationRuleName::String => RuleName::String,
            ValidationRuleName::Integer => RuleName::Integer,
            ValidationRuleName::Decimal => RuleName::Decimal,
            ValidationRuleName::Boolean => RuleName::Boolean,
            ValidationRuleName::Date => RuleName::Date,
            ValidationRuleName::Datetime => RuleName::Datetime,
            ValidationRuleName::Object => RuleName::Object,
            ValidationRuleName::List => RuleName::List,
            ValidationRuleName::PortableText => RuleName::PortableText,
            ValidationRuleName::MinLength => RuleName::MinLength,
            ValidationRuleName::MaxLength => RuleName::MaxLength,
            ValidationRuleName::Min => RuleName::Min,
            ValidationRuleName::Max => RuleName::Max,
            ValidationRuleName::Format => RuleName::Format,
            ValidationRuleName::In => RuleName::In,
            ValidationRuleName::ItemsIn => RuleName::ItemsIn,
            ValidationRuleName::Distinct => RuleName::Distinct,
            ValidationRuleName::MinItems => RuleName::MinItems,
            ValidationRuleName::MaxItems => RuleName::MaxItems,
            ValidationRuleName::Styles => RuleName::Styles,
            ValidationRuleName::Marks => RuleName::Marks,
            ValidationRuleName::Lists => RuleName::Lists,
            ValidationRuleName::Links => RuleName::Links,
        };
    }

    /**
     * A PHP string literal in single quotes.
     */
    private function literal(string $value): string
    {
        return "'".addcslashes($value, "\\'")."'";
    }

    private function line(int $depth, string $text): string
    {
        return str_repeat(self::INDENT, $depth).$text;
    }
}
