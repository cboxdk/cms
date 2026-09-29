<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use InvalidArgumentException;

/**
 * Rules that contradict themselves: a rule with the wrong arguments, a field whose rules do not
 * start with one type rule or name one twice, or a handle or namespace given twice. Rules come
 * from the code cms:generate writes, so this is a fault in that code, never in the input a
 * validator checks; the input's faults are the errors of a ValidationReport.
 */
#[Experimental]
final class InvalidRules extends InvalidArgumentException
{
    public static function argumentCount(RuleName $rule, int $expected, int $given): self
    {
        return new self(sprintf('The rule %s takes %s, got %d.', $rule->value, $expected === 1 ? 'one argument' : sprintf('%d arguments', $expected), $given));
    }

    public static function argument(RuleName $rule, string $expected, string $given): self
    {
        return new self(sprintf('The rule %s takes %s, got "%s".', $rule->value, $expected, self::shown($given)));
    }

    public static function noTypeRule(FieldHandle $field): self
    {
        return new self(sprintf('The rules of the field %s start with a type rule, such as string or object, and have only one.', $field->value));
    }

    public static function notAllowed(FieldHandle $field, RuleName $type, RuleName $rule): self
    {
        return new self(sprintf('The field %s has the type rule %s, which the rule %s cannot follow, or it names %s twice.', $field->value, $type->value, $rule->value, $rule->value));
    }

    public static function bound(FieldHandle $field, RuleName $type, string $bound): self
    {
        return new self(sprintf('The bound "%s" of the field %s is not a value of its type rule %s.', self::shown($bound), $field->value, $type->value));
    }

    public static function nestedFields(FieldHandle $field, RuleName $type): self
    {
        return new self(sprintf('The field %s has the type rule %s: only an object has nested fields and needs them, and a list has them or items_in.', $field->value, $type->value));
    }

    public static function repeatedField(FieldHandle $field): self
    {
        return new self(sprintf('The field %s is given twice among its siblings.', $field->value));
    }

    public static function repeatedNamespace(FieldNamespace $namespace): self
    {
        return new self(sprintf('The extension namespace %s is given twice.', $namespace->value));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > 64 ? substr($value, 0, 64).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
