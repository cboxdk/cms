<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use DateTimeImmutable;

/**
 * What the DTO and codec emitters write alike (GUARDRAILS 2.2): the PHP types of a value, the
 * classes they import, and PHP literals.
 */
#[Internal]
final readonly class PhpSource
{
    public const string OMITTED = Omitted::class;

    public const string CLASSIFICATION_ACCESS = ClassificationAccess::class;

    public const string LIST_VALUE = ListValue::class;

    /**
     * The native PHP type of a value, with classes by their short name.
     */
    public static function native(CodecValue $value): string
    {
        return match ($value->kind) {
            CodecKind::Text, CodecKind::Decimal, CodecKind::Choice => 'string',
            CodecKind::Integer => 'int',
            CodecKind::Boolean => 'bool',
            CodecKind::Date, CodecKind::Datetime => 'DateTimeImmutable',
            CodecKind::PortableText => 'ListValue',
            CodecKind::Object => $value->object->className ?? self::missing($value, 'object'),
            CodecKind::List => 'array',
            CodecKind::Id, CodecKind::Enum, CodecKind::Value => self::shortName($value->class ?? self::missing($value, 'class')),
        };
    }

    /**
     * The PHPDoc type of a value, which says more than the native type where PHPStan can check it:
     * `numeric-string`, the literal values of a choice, and the items of a list.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput for a choice without values
     */
    public static function doc(CodecValue $value): string
    {
        return match ($value->kind) {
            CodecKind::Decimal => 'numeric-string',
            CodecKind::Choice => implode('|', array_map(self::string(...), self::choices($value))),
            CodecKind::List => 'list<'.self::doc($value->item ?? self::missing($value, 'item')).'>',
            default => self::native($value),
        };
    }

    /**
     * The native type of a property: its value's, with Omitted when it may be left out and null when
     * it may be null.
     */
    public static function propertyNative(CodecProperty $property): string
    {
        return self::native($property->value).self::absent($property);
    }

    public static function propertyDoc(CodecProperty $property): string
    {
        return self::doc($property->value).self::absent($property);
    }

    /**
     * The classes the types of a value name, fully qualified: the class of a bound object, but
     * not the generated objects of the DTO's own namespace.
     *
     * @return list<string>
     */
    public static function imports(CodecValue $value): array
    {
        return match ($value->kind) {
            CodecKind::Date, CodecKind::Datetime => [DateTimeImmutable::class],
            CodecKind::PortableText => [self::LIST_VALUE],
            CodecKind::Id, CodecKind::Enum, CodecKind::Value => [$value->class ?? self::missing($value, 'class')],
            CodecKind::Object => $value->object instanceof CodecObject && $value->object->class !== null ? [$value->object->class] : [],
            CodecKind::List => self::imports($value->item ?? self::missing($value, 'item')),
            default => [],
        };
    }

    /**
     * The first rule of a name, or null.
     */
    public static function rule(CodecValue $value, ValidationRuleName $name): ?ValidationRule
    {
        foreach ($value->rules as $rule) {
            if ($rule->name === $name) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * The values of a choice's `in` rule.
     *
     * @return non-empty-list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    public static function choices(CodecValue $value): array
    {
        $arguments = self::rule($value, ValidationRuleName::In)->arguments ?? [];

        if ($arguments === []) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, 'A choice needs an `in` rule with at least one value.');
        }

        return $arguments;
    }

    /**
     * Refuses a JSON key that is not a name, a letter or an underscore followed by letters, digits
     * and underscores, because the path of a value in an error names its keys (FieldPath) and the
     * codec writes it as a property of an object.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    public static function assertKey(CodecProperty $property, CodecObject $object): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $property->key) !== 1) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf(
                'The key "%s" of %s is not a name: a letter or an underscore followed by letters, digits and underscores.',
                $property->key,
                $object->className,
            ));
        }
    }

    /**
     * Refuses a rule that the value's kind does not take, in the value and in its items.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    public static function assertRules(CodecValue $value, string $where): void
    {
        foreach ($value->rules as $rule) {
            if (! in_array($rule->name, $value->kind->rules(), true)) {
                throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf(
                    'The codec of %s has no form for the rule %s on a value of the kind %s.',
                    $where,
                    $rule->name->value,
                    $value->kind->value,
                ));
            }
        }

        if ($value->item instanceof CodecValue) {
            self::assertRules($value->item, $where);
        }
    }

    /**
     * A PHP string literal in single quotes.
     */
    public static function string(string $value): string
    {
        return "'".addcslashes($value, "'\\")."'";
    }

    /**
     * A PHP list literal of strings.
     *
     * @param  list<string>  $values
     */
    public static function strings(array $values): string
    {
        return '['.implode(', ', array_map(self::string(...), $values)).']';
    }

    /**
     * An integer argument of a rule as a PHP literal.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput when it is not an integer
     */
    public static function integer(string $value): string
    {
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('The rule argument "%s" is not an integer.', $value));
        }

        return $value;
    }

    /**
     * A PHPDoc text on one line, which cannot end its comment.
     */
    public static function docText(string $text): string
    {
        return str_replace('*/', '* /', trim((string) preg_replace('/\s+/', ' ', $text)));
    }

    public static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * `use` lines for classes, sorted and each once, without the classes of $namespace.
     *
     * @param  list<string>  $classes
     * @return list<string>
     */
    public static function uses(array $classes, string $namespace): array
    {
        $classes = array_values(array_unique(array_filter(
            $classes,
            static fn (string $class): bool => substr($class, 0, (int) strrpos($class, '\\')) !== $namespace,
        )));
        sort($classes, SORT_STRING | SORT_FLAG_CASE);

        return array_map(static fn (string $class): string => 'use '.$class.';', $classes);
    }

    private static function absent(CodecProperty $property): string
    {
        return ($property->omittable() ? '|Omitted' : '').($property->mayBeNull() ? '|null' : '');
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function missing(CodecValue $value, string $what): never
    {
        throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('A value of the kind %s has no %s.', $value->kind->value, $what));
    }
}
