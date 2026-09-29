<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Validation\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\DecimalNumber;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\RuleValues;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use DateTimeImmutable;

/**
 * Checks input from outside the repository against a type's rules (PRD 11.8, 11.12 "Hvor
 * statiske typer slutter"): a command's fields from the REST API, MCP, the CLI or a sidecar, as
 * JSON decodes them, to arrays or to objects.
 *
 * The input is an object of the owner's fields by handle, with the extension fields under `ext`,
 * an object of namespaces, each an object of that extender's fields (PRD 11.12 point 2). Each
 * field is checked against its rules; a field that is left out or null only needs a value when its
 * Presence requires one at the stage. A key the type does not have, in the namespace it is given
 * in, is an error, so is a namespace no extender has.
 *
 * It never throws for bad input: it returns every error it finds, at most MAX_ERRORS, each with the
 * path of the value and the code of the rule it breaks. Once a value has the wrong type, its other
 * rules are not checked; a list with more items than its field allows is not checked item by item.
 */
#[Experimental]
final readonly class InputValidator
{
    /** The most errors one report holds; the validator stops walking the input when it has them. */
    public const int MAX_ERRORS = 100;

    /**
     * @param  ?FieldPath  $at  the path of the input in the command, such as `fields`; null when the
     *                          paths start at the field handles
     */
    public function validate(TypeRules $rules, mixed $input, ValidationStage $stage = ValidationStage::Write, ?FieldPath $at = null): ValidationReport
    {
        $errors = new ValidationErrors(self::MAX_ERRORS);
        $object = InputPaths::object($input);

        if ($object === null) {
            $errors->add(ErrorCode::ValidationWrongType, $at, 'is not an object of the type\'s fields by handle.');

            return new ValidationReport($errors->all());
        }

        $extensions = $object[TypeRules::EXTENSIONS_KEY] ?? null;
        unset($object[TypeRules::EXTENSIONS_KEY]);
        $this->fields($rules->fields, $object, $at, $stage, $errors);
        $this->extensions($rules->extensions, $extensions, InputPaths::below($at, TypeRules::EXTENSIONS_KEY), $stage, $errors);

        return new ValidationReport($errors->all());
    }

    /**
     * Checks input against the rules a generated validator declares.
     */
    public function validateWith(TypeValidator $validator, mixed $input, ValidationStage $stage = ValidationStage::Write, ?FieldPath $at = null): ValidationReport
    {
        return $this->validate($validator->rules(), $input, $stage, $at);
    }

    /**
     * @param  list<ExtensionRules>  $rules
     */
    private function extensions(array $rules, mixed $input, FieldPath $at, ValidationStage $stage, ValidationErrors $errors): void
    {
        $object = $input === null ? [] : InputPaths::object($input);

        if ($object === null) {
            $errors->add(ErrorCode::ValidationWrongType, $at, 'is not an object of the extension fields by namespace.');

            return;
        }

        $known = [];

        foreach ($rules as $extension) {
            $namespace = $extension->namespace->value;
            $known[$namespace] = true;
            $fields = $object[$namespace] ?? null;
            $members = $fields === null ? [] : InputPaths::object($fields);
            $path = $at->then($namespace);

            if ($members === null) {
                $errors->add(ErrorCode::ValidationWrongType, $path, sprintf('is not an object of the fields of the namespace %s by handle.', $namespace));

                continue;
            }

            $this->fields($extension->fields, $members, $path, $stage, $errors);
        }

        foreach (array_keys($object) as $key) {
            if (! isset($known[$key])) {
                $this->unknown($key, $at, 'the type has no extension fields in the namespace', $errors);
            }
        }
    }

    /**
     * @param  list<FieldRules>  $rules
     * @param  array<array-key, mixed>  $object
     */
    private function fields(array $rules, array $object, ?FieldPath $at, ValidationStage $stage, ValidationErrors $errors): void
    {
        $known = [];

        foreach ($rules as $field) {
            $handle = $field->handle->value;
            $known[$handle] = true;
            $value = $object[$handle] ?? null;
            $path = InputPaths::below($at, $handle);

            if ($value === null) {
                if ($field->presence->requiredAt($stage)) {
                    $errors->add(ErrorCode::ValidationRequired, $path, 'needs a value.');
                }

                continue;
            }

            $this->value($field, $value, $path, $stage, $errors);
        }

        foreach (array_keys($object) as $key) {
            if (! isset($known[$key])) {
                $this->unknown($key, $at, 'there is no such field here', $errors);
            }
        }
    }

    private function unknown(int|string $key, ?FieldPath $at, string $why, ValidationErrors $errors): void
    {
        if (InputPaths::nameable($key)) {
            $errors->add(ErrorCode::ValidationUnknownField, InputPaths::below($at, (string) $key), sprintf('is not known: %s.', $why));

            return;
        }

        $errors->add(ErrorCode::ValidationUnknownField, $at, sprintf('has the key %s, which is not known: %s.', InputPaths::shown($key), $why));
    }

    private function value(FieldRules $field, mixed $value, FieldPath $path, ValidationStage $stage, ValidationErrors $errors): void
    {
        if ($errors->full()) {
            return;
        }

        match ($field->type) {
            RuleName::String => $this->text($field, $value, $path, $errors),
            RuleName::Integer => $this->integer($field, $value, $path, $errors),
            RuleName::Decimal => $this->decimal($field, $value, $path, $errors),
            RuleName::Boolean => is_bool($value) || $this->wrongType($path, 'is not true or false', $errors),
            RuleName::Date => $this->date($field, $value, $path, $errors),
            RuleName::Datetime => $this->datetime($field, $value, $path, $errors),
            RuleName::Object => $this->group($field, $value, $path, $stage, $errors),
            RuleName::List => $this->list($field, $value, $path, $stage, $errors),
            default => PortableTextInput::check($field, $value, $path, $errors),
        };
    }

    private function text(FieldRules $field, mixed $value, FieldPath $path, ValidationErrors $errors): bool
    {
        if (! is_string($value)) {
            return $this->wrongType($path, 'is not text', $errors);
        }

        if (! self::storableText($value)) {
            return $this->wrongType($path, 'is not text a column can store: it is not valid UTF-8, or it holds the character U+0000', $errors);
        }

        $length = mb_strlen($value, 'UTF-8');
        $min = $field->rule(RuleName::MinLength);
        $max = $field->rule(RuleName::MaxLength);

        if ($min instanceof Rule && $length < $min->number()) {
            $errors->add(ErrorCode::ValidationTooShort, $path, sprintf('has %d characters; it needs at least %d.', $length, $min->number()));
        }

        if ($max instanceof Rule && $length > $max->number()) {
            $errors->add(ErrorCode::ValidationTooLong, $path, sprintf('has %d characters; it may have at most %d.', $length, $max->number()));
        }

        $format = $field->rule(RuleName::Format)?->arguments[0] ?? null;

        if ($format !== null && ! self::inFormat($format, $value)) {
            $errors->add(ErrorCode::ValidationInvalidFormat, $path, $format === 'email' ? 'is not an email address.' : 'is not an absolute http or https URL.');
        }

        $options = $field->rule(RuleName::In)?->arguments;

        if ($options !== null && ! in_array($value, $options, true)) {
            $errors->add(ErrorCode::ValidationNotAnOption, $path, sprintf('is not one of the options: %s.', implode(', ', $options)));
        }

        return true;
    }

    private function integer(FieldRules $field, mixed $value, FieldPath $path, ValidationErrors $errors): bool
    {
        if (! is_int($value)) {
            return $this->wrongType($path, 'is not a whole number', $errors);
        }

        $this->bounds($field, $path, $errors, static fn (string $bound): int => $value <=> (int) $bound);

        return true;
    }

    private function decimal(FieldRules $field, mixed $value, FieldPath $path, ValidationErrors $errors): bool
    {
        $number = is_string($value) ? DecimalNumber::parse($value) : null;

        if (! $number instanceof DecimalNumber) {
            return $this->wrongType($path, 'is not a decimal number written as a string, such as "12.50"', $errors);
        }

        $digits = $field->rules[0]->arguments;
        $precision = (int) ($digits[0] ?? '0');
        $scale = (int) ($digits[1] ?? '0');

        if (! $number->fits($precision, $scale)) {
            $errors->add(ErrorCode::ValidationTooManyDigits, $path, sprintf('has more digits than the field stores: at most %d before the point and %d after it.', $precision - $scale, $scale));
        }

        $this->bounds($field, $path, $errors, static fn (string $bound): int => $number->compare(DecimalNumber::parse($bound) ?? $number));

        return true;
    }

    private function date(FieldRules $field, mixed $value, FieldPath $path, ValidationErrors $errors): bool
    {
        if (! is_string($value) || ! RuleValues::date($value)) {
            return $this->wrongType($path, 'is not a date written YYYY-MM-DD', $errors);
        }

        $this->bounds($field, $path, $errors, static fn (string $bound): int => strcmp($value, $bound) <=> 0);

        return true;
    }

    private function datetime(FieldRules $field, mixed $value, FieldPath $path, ValidationErrors $errors): bool
    {
        $instant = is_string($value) ? RuleValues::datetime($value) : null;

        if (! $instant instanceof DateTimeImmutable) {
            return $this->wrongType($path, 'is not a date-time of RFC 3339 with its offset, such as "2026-09-29T12:00:00Z"', $errors);
        }

        $this->bounds($field, $path, $errors, static fn (string $bound): int => $instant <=> (RuleValues::datetime($bound) ?? $instant));

        return true;
    }

    /**
     * Reports a value below the field's `min` or above its `max`.
     *
     * @param  callable(string): int  $compare  the value compared with a bound
     */
    private function bounds(FieldRules $field, FieldPath $path, ValidationErrors $errors, callable $compare): void
    {
        $min = $field->rule(RuleName::Min)?->arguments[0] ?? null;
        $max = $field->rule(RuleName::Max)?->arguments[0] ?? null;

        if ($min !== null && $compare($min) < 0) {
            $errors->add(ErrorCode::ValidationBelowMinimum, $path, sprintf('is below the minimum %s.', $min));
        }

        if ($max !== null && $compare($max) > 0) {
            $errors->add(ErrorCode::ValidationAboveMaximum, $path, sprintf('is above the maximum %s.', $max));
        }
    }

    private function group(FieldRules $field, mixed $value, FieldPath $path, ValidationStage $stage, ValidationErrors $errors): bool
    {
        $object = InputPaths::object($value);

        if ($object === null) {
            return $this->wrongType($path, 'is not an object of the group\'s fields by handle', $errors);
        }

        $this->fields($field->fields, $object, $path, $stage, $errors);

        return true;
    }

    private function list(FieldRules $field, mixed $value, FieldPath $path, ValidationStage $stage, ValidationErrors $errors): bool
    {
        $items = InputPaths::list($value);

        if ($items === null) {
            return $this->wrongType($path, 'is not a list', $errors);
        }

        $count = count($items);
        $min = $field->rule(RuleName::MinItems);
        $max = $field->rule(RuleName::MaxItems);

        if ($min instanceof Rule && $count < $min->number()) {
            $errors->add(ErrorCode::ValidationTooFewItems, $path, sprintf('has %d items; it needs at least %d.', $count, $min->number()));
        }

        if ($max instanceof Rule && $count > $max->number()) {
            $errors->add(ErrorCode::ValidationTooManyItems, $path, sprintf('has %d items; it may have at most %d.', $count, $max->number()));

            return true;
        }

        $options = $field->rule(RuleName::ItemsIn)?->arguments;
        $distinct = $field->rule(RuleName::Distinct) instanceof Rule;
        $seen = [];

        foreach ($items as $index => $item) {
            $itemPath = $path->then($index);

            if ($options === null) {
                $object = InputPaths::object($item);

                if ($object === null) {
                    $this->wrongType($itemPath, 'is not an object of the group\'s fields by handle', $errors);
                } else {
                    $this->fields($field->fields, $object, $itemPath, $stage, $errors);
                }

                continue;
            }

            if (! is_string($item)) {
                $this->wrongType($itemPath, 'is not text', $errors);
            } elseif (! in_array($item, $options, true)) {
                $errors->add(ErrorCode::ValidationNotAnOption, $itemPath, sprintf('is not one of the options: %s.', implode(', ', $options)));
            } elseif ($distinct && isset($seen[$item])) {
                $errors->add(ErrorCode::ValidationDuplicateItem, $itemPath, sprintf('is the same option as item %d.', $seen[$item]));
            } else {
                $seen[$item] = $index;
            }
        }

        return true;
    }

    private function wrongType(?FieldPath $path, string $what, ValidationErrors $errors): bool
    {
        $errors->add(ErrorCode::ValidationWrongType, $path, $what.'.');

        return false;
    }

    /**
     * Whether a text column, and a string in jsonb, can hold $value: valid UTF-8 without U+0000.
     */
    public static function storableText(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }

    /**
     * Whether $value is in the text format: `email`, an email address, or `url`, an absolute
     * http or https URL.
     */
    public static function inFormat(string $format, string $value): bool
    {
        if ($format === 'email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('/\Ahttps?\z/i', (string) parse_url($value, PHP_URL_SCHEME)) === 1;
    }
}
