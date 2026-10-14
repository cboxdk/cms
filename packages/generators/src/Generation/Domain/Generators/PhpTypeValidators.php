<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Validation\Condition;
use Cbox\Cms\Contracts\Validation\ConditionOperator;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldReference;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\WhenHidden;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generator;
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
 *
 * A conditional field (gap G3) keeps the presence its blueprint gives it, which holds while it is
 * shown, and declares its condition and its when_hidden, so the InputValidator never requires it
 * while it is hidden and refuses a value for it while it is hidden and clear.
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
        'link' => 'link',
        'long_text' => 'long_text',
        'relation' => 'relation',
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
            array_push($fields, ...$this->field($field, $field->presence(), 4));
        }

        $arguments = [$this->line(3, '['), ...$fields, $this->line(3, '],')];

        if ($extensions !== []) {
            $arguments[] = $this->line(3, '[');

            foreach ($extensions as $namespace => $extensionFields) {
                $arguments[] = $this->line(4, 'new ExtensionRules(new FieldNamespace('.$this->literal($namespace).'), [');

                foreach ($extensionFields as $field) {
                    array_push($arguments, ...$this->field($field, $field->presence(), 5));
                }

                $arguments[] = $this->line(4, ']),');
            }

            $arguments[] = $this->line(3, '],');
        }

        $conditional = array_any($type->fields, self::conditional(...));
        $uses = [
            FieldHandle::class,
            ...($extensions === [] ? [] : [FieldNamespace::class, ExtensionRules::class]),
            ...($conditional ? [Condition::class, FieldReference::class, WhenHidden::class] : []),
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
            array_push($lines, ...$this->fieldLines($field, '', $field->presence()));
        }

        foreach ($type->extensionFields() as $namespace => $fields) {
            foreach ($fields as $field) {
                array_push($lines, ...$this->fieldLines($field, 'ext.'.$namespace.'.', $field->presence()));
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
        $lines = [sprintf(' *   %s: %s, %s%s', $address, GeneratedLines::typeName(self::class, self::FIELD_TYPES, $field), match ($presence) {
            Presence::Required => 'required',
            Presence::RequiredOnRelease => 'required on release',
            Presence::Optional => 'optional',
        }, $field->whenHidden instanceof WhenHidden ? sprintf(', shown on a condition, %s when hidden', $field->whenHidden->value) : '')];

        $repeated = array_any($field->validation, static fn (ValidationRule $rule): bool => $rule->name === ValidationRuleName::List);

        foreach ($field->fields as $nested) {
            array_push($lines, ...$this->fieldLines($nested, $address.($repeated ? '[].' : '.'), $nested->presence()));
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
                array_push($lines, ...$this->field($nested, $nested->presence(), $depth + 2));
            }

            $lines[] = $this->line($depth + 1, '],');
        } elseif ($field->translatable) {
            $lines[] = $this->line($depth + 1, '[],');
        }

        if ($field->translatable) {
            $lines[] = $this->line($depth + 1, 'true,');
        }

        if ($field->when instanceof Condition && $field->whenHidden instanceof WhenHidden) {
            $condition = $this->condition($field->when, $depth + 1);
            $condition[0] = $this->line($depth + 1, 'when: '.ltrim($condition[0]));
            $condition[count($condition) - 1] .= ',';
            array_push($lines, ...$condition);
            $lines[] = $this->line($depth + 1, 'whenHidden: WhenHidden::'.$field->whenHidden->name.',');
        }

        $lines[] = $this->line($depth, '),');

        return $lines;
    }

    /**
     * Whether the field or a field nested in it is conditional.
     */
    private static function conditional(FieldDescriptor $field): bool
    {
        return $field->when instanceof Condition || array_any($field->fields, self::conditional(...));
    }

    /**
     * The lines of a Condition expression, the first indented as the others, without a comma.
     *
     * @return list<string>
     */
    private function condition(Condition $condition, int $depth): array
    {
        $field = $condition->field;

        if (! $field instanceof FieldReference) {
            $lines = [$this->line($depth, sprintf('Condition::%s([', $condition->operator === ConditionOperator::All ? 'all' : 'any'))];

            foreach ($condition->conditions as $inner) {
                $innerLines = $this->condition($inner, $depth + 1);
                $innerLines[count($innerLines) - 1] .= ',';
                array_push($lines, ...$innerLines);
            }

            $lines[] = $this->line($depth, '])');

            return $lines;
        }

        $reference = sprintf('new FieldReference(%d, new FieldHandle(%s))', $field->up, $this->literal($field->handle->value));
        $values = array_map($this->value(...), $condition->values);

        return [$this->line($depth, match ($condition->operator) {
            ConditionOperator::Eq => sprintf('Condition::eq(%s, %s)', $reference, $values[0] ?? ''),
            ConditionOperator::Neq => sprintf('Condition::neq(%s, %s)', $reference, $values[0] ?? ''),
            ConditionOperator::In => sprintf('Condition::in(%s, [%s])', $reference, implode(', ', $values)),
            ConditionOperator::Null => sprintf('Condition::isNull(%s)', $reference),
            default => sprintf('Condition::notNull(%s)', $reference),
        })];
    }

    /**
     * A value of a condition as a PHP literal.
     */
    private function value(string|int|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            default => $this->literal($value),
        };
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
            ValidationRuleName::Relation => RuleName::Relation,
            ValidationRuleName::Relations => RuleName::Relations,
            ValidationRuleName::Link => RuleName::Link,
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
            ValidationRuleName::Targets => RuleName::Targets,
            ValidationRuleName::Kinds => RuleName::Kinds,
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
