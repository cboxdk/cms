<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeOptions;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDate;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDatetime;
use Cbox\Cms\Generators\Schema\Domain\DecimalBound;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\FieldValues;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Cbox\Cms\Generators\Schema\Domain\TypeId;
use Closure;
use Override;
use stdClass;

/**
 * The values of one decoded object of a blueprint document that the blueprint schema v1 has
 * accepted. Whatever it cannot map is recorded in the document's ReadProblems as
 * generate_schema_unsupported_version at its JSON pointer: the installed schema allowed it, so the
 * file needs a newer cboxdk/cms, and nothing in a file is dropped without a word.
 */
#[Internal]
final readonly class DocumentValues implements FieldValues
{
    /**
     * @param  Closure(mixed, SourceLocation): ?list<FieldBlueprint>  $nestedFields  reads a list of nested fields
     */
    public function __construct(
        private stdClass $object,
        private SourceLocation $at,
        private ReadProblems $problems,
        private Closure $nestedFields,
        private OptionsSchemas $optionsSchemas = new OptionsSchemas,
    ) {}

    /**
     * The same values, with nested fields read by the given reader instead.
     *
     * @param  Closure(mixed, SourceLocation): ?list<FieldBlueprint>  $nestedFields
     */
    public function withNestedFields(Closure $nestedFields): self
    {
        return new self($this->object, $this->at, $this->problems, $nestedFields, $this->optionsSchemas);
    }

    #[Override]
    public function at(): SourceLocation
    {
        return $this->at;
    }

    #[Override]
    public function has(string $key): bool
    {
        return property_exists($this->object, $key);
    }

    /**
     * The decoded value at the key, or null when the object has no such key.
     */
    public function value(string $key): mixed
    {
        return $this->object->{$key} ?? null;
    }

    #[Override]
    public function knownKeys(array $known): void
    {
        foreach (array_keys(get_object_vars($this->object)) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $this->problems->add(self::unsupported($this->at->below((string) $key), sprintf('the key "%s" is not one this %s knows', $key, BlueprintDocumentReader::PACKAGE)));
            }
        }
    }

    #[Override]
    public function string(string $key): ?string
    {
        $value = $this->value($key);

        if (! is_string($value)) {
            $this->unreadable($key);

            return null;
        }

        return $value;
    }

    #[Override]
    public function optionalString(string $key): ?string
    {
        return $this->has($key) ? $this->string($key) : null;
    }

    #[Override]
    public function optionalDecimal(string $key): ?DecimalBound
    {
        return $this->optionalValue($key, static fn (string $value): DecimalBound => new DecimalBound($value));
    }

    #[Override]
    public function optionalDate(string $key): ?BlueprintDate
    {
        return $this->optionalValue($key, static fn (string $value): BlueprintDate => new BlueprintDate($value));
    }

    #[Override]
    public function optionalDatetime(string $key): ?BlueprintDatetime
    {
        return $this->optionalValue($key, static fn (string $value): BlueprintDatetime => new BlueprintDatetime($value));
    }

    #[Override]
    public function int(string $key): ?int
    {
        $value = $this->value($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value && $value >= PHP_INT_MIN && $value < PHP_INT_MAX) {
            return (int) $value;
        }

        $this->unreadable($key);

        return null;
    }

    #[Override]
    public function optionalInt(string $key): ?int
    {
        return $this->has($key) ? $this->int($key) : null;
    }

    #[Override]
    public function bool(string $key, bool $default): bool
    {
        if (! $this->has($key)) {
            return $default;
        }

        $value = $this->value($key);

        if (! is_bool($value)) {
            $this->unreadable($key);

            return $default;
        }

        return $value;
    }

    #[Override]
    public function handle(string $key): ?Handle
    {
        $value = $this->string($key);

        if ($value === null) {
            return null;
        }

        try {
            return new Handle($value);
        } catch (GenerationFailed) {
            $this->unreadable($key);

            return null;
        }
    }

    /**
     * The type id at the key, which the object must have.
     */
    public function typeId(string $key): ?TypeId
    {
        $value = $this->string($key);

        if ($value === null) {
            return null;
        }

        try {
            return TypeId::fromString($value);
        } catch (InvalidUuid7) {
            $this->unreadable($key);

            return null;
        }
    }

    /**
     * The case of the enum at the key, which the object must have.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return ?T
     */
    public function enum(string $enum, string $key): ?BackedEnum
    {
        $value = $this->value($key);
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            $this->unknown($value, $this->at->below($key));
        }

        return $case;
    }

    #[Override]
    public function optionalEnum(string $enum, string $key): ?BackedEnum
    {
        return $this->has($key) ? $this->enum($enum, $key) : null;
    }

    #[Override]
    public function enumList(string $enum, string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        $values = $this->value($key);
        $listAt = $this->at->below($key);

        if (! is_array($values) || ! array_is_list($values)) {
            $this->unreadable($key);

            return null;
        }

        $cases = [];

        foreach ($values as $index => $value) {
            $case = is_string($value) ? $enum::tryFrom($value) : null;

            if ($case === null) {
                $this->unknown($value, $listAt->below($index));
            } else {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    #[Override]
    public function object(string $key): ?self
    {
        $value = $this->value($key);

        if (! $value instanceof stdClass) {
            $this->unreadable($key);

            return null;
        }

        return $this->below($value, $this->at->below($key));
    }

    #[Override]
    public function objectList(string $key): ?array
    {
        $list = $this->value($key);
        $listAt = $this->at->below($key);

        if (! is_array($list) || ! array_is_list($list)) {
            $this->unreadable($key);

            return null;
        }

        $objects = [];

        foreach ($list as $index => $item) {
            if ($item instanceof stdClass) {
                $objects[] = $this->below($item, $listAt->below($index));
            } else {
                $this->problems->add(self::unreadableAt($listAt->below($index)));
            }
        }

        return count($objects) === count($list) ? $objects : null;
    }

    #[Override]
    public function fields(string $key): ?array
    {
        return ($this->nestedFields)($this->value($key), $this->at->below($key));
    }

    #[Override]
    public function fieldTypeOptions(string $key, string $fieldType, string $schema): ?FieldTypeOptions
    {
        $options = $this->has($key) ? $this->value($key) : new stdClass;
        $at = $this->at->below($key);

        if (! $options instanceof stdClass) {
            $this->unreadable($key);

            return null;
        }

        try {
            $violations = $this->optionsSchemas->check($fieldType, $schema, $options, $at);
        } catch (GenerationFailed $unusable) {
            $violations = $unusable->problems;
        }

        foreach ($violations as $violation) {
            $this->problems->add($violation);
        }

        if ($violations !== []) {
            return null;
        }

        return $this->options($options, $at);
    }

    #[Override]
    public function invalid(string $key, string $reason): void
    {
        $this->problems->add(new GenerationProblem(GenerateErrorCode::SchemaInvalid, sprintf('%s: %s', $this->at->below($key)->describe(), $reason)));
    }

    /**
     * Records the value at the key as one this generator does not know, and reads it as null.
     */
    public function unknownAt(string $key): null
    {
        $this->unknown($this->value($key), $this->at->below($key));

        return null;
    }

    /**
     * Records the value at the key, or the object itself, as a kind this generator cannot read.
     */
    public function unreadable(?string $key = null): void
    {
        $this->problems->add(self::unreadableAt($key === null ? $this->at : $this->at->below($key)));
    }

    /**
     * Records a problem that is not the generator's, such as a rule of the registry.
     */
    public function problem(GenerationProblem $problem): void
    {
        $this->problems->add($problem);
    }

    /**
     * A value of a kind this generator cannot map, which the installed blueprint schema allowed.
     */
    public static function unreadableAt(SourceLocation $at): GenerationProblem
    {
        return self::unsupported($at, sprintf('the value is not of a kind this %s can read', BlueprintDocumentReader::PACKAGE));
    }

    private static function unsupported(SourceLocation $at, string $what): GenerationProblem
    {
        return new GenerationProblem(GenerateErrorCode::SchemaUnsupportedVersion, sprintf(
            '%s: %s. The installed blueprint schema allows it, so the file needs a newer %s.',
            $at->describe(),
            $what,
            BlueprintDocumentReader::PACKAGE,
        ));
    }

    private function unknown(mixed $value, SourceLocation $at): void
    {
        $this->problems->add(is_string($value)
            ? self::unsupported($at, sprintf('the value "%s" is not one this %s knows', $value, BlueprintDocumentReader::PACKAGE))
            : self::unreadableAt($at));
    }

    /**
     * The value object made from the string at the key, or null when the object has no such key. A
     * string the value object refuses was allowed by the installed blueprint schema, so it is
     * recorded as unreadable, as for a handle.
     *
     * @template T of object
     *
     * @param  Closure(string): T  $make  throws GenerationFailed for a string of another form
     * @return ?T
     */
    private function optionalValue(string $key, Closure $make): ?object
    {
        $value = $this->optionalString($key);

        if ($value === null) {
            return null;
        }

        try {
            return $make($value);
        } catch (GenerationFailed) {
            $this->unreadable($key);

            return null;
        }
    }

    /**
     * The decoded options as FieldTypeOptions, or null when they hold a value FieldTypeOptions
     * does not hold, such as a list inside a list; that is recorded at the value.
     */
    private function options(stdClass $object, SourceLocation $at): ?FieldTypeOptions
    {
        $readable = true;
        $values = [];

        foreach (get_object_vars($object) as $key => $value) {
            $key = (string) $key;
            $valueAt = $at->below($key);

            if (! is_array($value)) {
                $values[$key] = $this->optionValue($value, $valueAt, $readable);

                continue;
            }

            $items = [];

            foreach (array_values($value) as $index => $item) {
                if (is_array($item)) {
                    $this->problems->add(new GenerationProblem(GenerateErrorCode::SchemaInvalid, sprintf(
                        '%s: a list inside a list, which the options of a field type do not hold. Use a list of objects instead.',
                        $valueAt->below($index)->describe(),
                    )));
                    $readable = false;

                    continue;
                }

                $items[] = $this->optionValue($item, $valueAt->below($index), $readable);
            }

            $values[$key] = $items;
        }

        return $readable ? new FieldTypeOptions($values) : null;
    }

    /**
     * A value of the options that is not a list; $readable becomes false when it cannot be read.
     */
    private function optionValue(mixed $value, SourceLocation $at, bool &$readable): string|int|float|bool|FieldTypeOptions|null
    {
        if ($value instanceof stdClass) {
            $options = $this->options($value, $at);
            $readable = $readable && $options instanceof FieldTypeOptions;

            return $options;
        }

        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        $this->problems->add(self::unreadableAt($at));
        $readable = false;

        return null;
    }

    private function below(stdClass $object, SourceLocation $at): self
    {
        return new self($object, $at, $this->problems, $this->nestedFields, $this->optionsSchemas);
    }
}
