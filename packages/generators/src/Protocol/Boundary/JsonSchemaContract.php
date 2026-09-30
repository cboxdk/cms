<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Protocol\Boundary;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Generators\Codec\Domain\CodecKind;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecCommand;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\Dto\StringForm;
use Cbox\Cms\Generators\Codec\Domain\PhpSource;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\Dto\ValueBinding;
use Cbox\Cms\Generators\Protocol\Domain\FieldValuesSchema;
use DateTimeImmutable;
use JsonException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use stdClass;
use Throwable;

/**
 * Reads a kernel JSON Schema into the codec contract of its version (GUARDRAILS 2.2), bound to the
 * classes of the contracts by its SchemaBinding.
 *
 * The schema is the source of the keys, of which are required, nullable or have a default, and of
 * the rules the codec checks: minLength and maxLength of text, minimum and maximum of an integer,
 * minItems and maxItems of a list, and the values of an enum. It reads draft 2020-12 and only the
 * keywords that have a form in the codec, and refuses every other, so no rule of a schema is ever
 * dropped without being checked:
 *
 * - an object has `properties`, `additionalProperties: false` and `required`; a property that is
 *   not required has a `default`, which the codec gives it when the key is missing;
 * - a value is `type` string, integer, boolean, array or object, or one of them and null; an
 *   object may be a `$ref` to `#/$defs/<name>`, and a nullable one `anyOf` of it and null;
 * - a string with `format: date-time` is a date-time, and `enum` is a value of a bound enum;
 * - an integer bound with ValueBinding::value() is a value object of one integer, whose `minimum`
 *   and `maximum` the codec checks before its constructor does;
 * - a property bound with ValueBinding::fields() is the fields of a revision of any type: a
 *   `$ref` to `#/$defs/fields`, with the definitions of FieldValuesSchema exactly as they are there.
 *
 * `pattern`, and minLength and maxLength of a bound value, describe what the bound class's
 * constructor checks, for the other readers of the schema; the codec leaves the check to the class.
 * A plain string with a pattern is refused, because nothing would check it.
 *
 * Reflection on the bound classes is allowed here, at build time (GUARDRAILS 2.2): each object's
 * class must take a constructor argument per property, by name and of the property's type, and
 * expose each as a public property; an enum must have the schema's values; a default must be the
 * constructor's own.
 */
#[Internal]
final readonly class JsonSchemaContract
{
    private const string DRAFT = 'https://json-schema.org/draft/2020-12/schema';

    /** The keywords of the document besides those of an object. */
    private const array DOCUMENT_KEYWORDS = ['$schema', '$defs', 'title'];

    /** The keywords of a node that have a form in the codec. */
    private const array KEYWORDS = [
        '$ref', 'additionalProperties', 'anyOf', 'default', 'description', 'enum', 'format', 'items',
        'maxItems', 'maxLength', 'maximum', 'minItems', 'minLength', 'minimum', 'pattern', 'properties',
        'required', 'type',
    ];

    /** The prefix of a reference the reader resolves. */
    private const string DEFS = '#/$defs/';

    private function __construct(
        private stdClass $document,
        private SchemaBinding $binding,
    ) {}

    /**
     * The codec contract of the schema $json, as its binding binds it.
     *
     * @param  class-string  $attribute  the stability attribute the codec class carries
     *
     * @throws GenerationFailed with GenerateErrorCode::SchemaInvalid
     */
    public static function read(string $json, SchemaBinding $binding, string $attribute): CodecContract
    {
        try {
            $document = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw self::invalid($binding, '#', 'is not well-formed JSON: '.$exception->getMessage(), $exception);
        }

        if (! $document instanceof stdClass) {
            throw self::invalid($binding, '#', 'is not a JSON object');
        }

        if (($document->{'$schema'} ?? null) !== self::DRAFT) {
            throw self::invalid($binding, '#', sprintf('has no "$schema" of JSON Schema draft 2020-12 (%s)', self::DRAFT));
        }

        $reader = new self($document, $binding);
        $root = $reader->object($document, '#', '#', self::DOCUMENT_KEYWORDS);
        $reader->assertBindingsUsed();

        return new CodecContract(
            root: $root,
            codecClass: $binding->codecClass,
            version: $binding->version,
            summary: self::summary($document, $binding, $root),
            attribute: $attribute,
            command: $binding->command === null ? null : $reader->command($binding->command),
        );
    }

    /**
     * The command a command's schema is bound to: the class of the document, whose #[Command] gives
     * the name and must give the binding's version, with the schema as pretty-printed JSON.
     *
     * @throws GenerationFailed
     */
    private function command(string $class): CodecCommand
    {
        if (($this->binding->objects['#'] ?? null) !== $class || ! class_exists($class)) {
            throw $this->problem('#', sprintf('is the schema of the command %s, which is not a class or not the class the document is bound to', $class));
        }

        $attributes = new ReflectionClass($class)->getAttributes(Command::class);

        if (count($attributes) !== 1) {
            throw $this->problem('#', sprintf('is the schema of the command %s, which has no #[Command]', $class));
        }

        $command = $attributes[0]->newInstance();

        if ($command->version !== $this->binding->version) {
            throw $this->problem('#', sprintf('is version %d of a command, but #[Command] of %s gives version %d', $this->binding->version, $class, $command->version));
        }

        return new CodecCommand($command->name, (string) json_encode($this->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The object at $pointer, bound to the class of $definition.
     *
     * @param  list<string>  $extraKeywords  keywords the node may have besides KEYWORDS
     *
     * @throws GenerationFailed
     */
    private function object(stdClass $node, string $pointer, string $definition, array $extraKeywords = []): CodecObject
    {
        $this->assertKeywords($node, $pointer, $extraKeywords);

        if (($node->type ?? null) !== 'object') {
            throw $this->problem($pointer, 'is not an object with "type": "object"');
        }

        if (($node->additionalProperties ?? null) !== false) {
            throw $this->problem($pointer, 'needs "additionalProperties": false, because the codec refuses a key the contract does not have');
        }

        $class = $this->binding->objects[$definition] ?? throw $this->problem($pointer, sprintf('is an object that the binding binds to no class; add "%s" to its objects', $definition));

        if (! class_exists($class)) {
            throw $this->problem($pointer, sprintf('is bound to %s, which is not a class', $class));
        }
        $properties = $node->properties ?? null;
        $required = $node->required ?? [];

        if (! $properties instanceof stdClass || get_object_vars($properties) === []) {
            throw $this->problem($pointer, 'has no "properties"');
        }

        if (! is_array($required) || ! array_is_list($required) || array_any($required, static fn (mixed $key): bool => ! is_string($key) || ! property_exists($properties, $key))) {
            throw $this->problem($pointer, 'has a "required" that is not a list of its properties');
        }

        $read = [];

        foreach (get_object_vars($properties) as $key => $property) {
            $key = (string) $key;
            $at = $definition.'/properties/'.$key;

            if (! $property instanceof stdClass) {
                throw $this->problem($at, 'is not a schema object');
            }

            $read[] = $this->property($key, $property, $at, in_array($key, $required, true));
        }

        $object = new CodecObject(PhpSource::shortName($class), [self::text($node->description ?? '')], $read, $class);

        return new CodecObject($object->className, $object->summary, $object->properties, $class, $this->assertClass($object, $pointer, $definition));
    }

    /**
     * @throws GenerationFailed
     */
    private function property(string $key, stdClass $node, string $pointer, bool $required): CodecProperty
    {
        [$value, $nullable] = $this->value($node, $pointer);
        $hasDefault = property_exists($node, 'default');

        if ($required && $hasDefault) {
            throw $this->problem($pointer, 'is required and has a default; a required key has no default');
        }

        if (! $required && ! $hasDefault) {
            throw $this->problem($pointer, 'is not required and has no default; the codec gives a missing key its default');
        }

        return new CodecProperty(
            key: $key,
            name: $this->binding->names[$pointer] ?? lcfirst(str_replace('_', '', ucwords($key, '_'))),
            value: $value,
            required: $required,
            classification: null,
            description: self::text($node->description ?? ''),
            nullable: $nullable,
            default: $hasDefault ? $this->defaultExpression($node->default, $value, $nullable, $pointer) : null,
        );
    }

    /**
     * The value at $pointer and whether it may be null.
     *
     * @return array{CodecValue, bool}
     *
     * @throws GenerationFailed
     */
    private function value(stdClass $node, string $pointer): array
    {
        $this->assertKeywords($node, $pointer);

        if (($this->binding->values[$pointer] ?? null)?->kind === CodecKind::Fields) {
            return [$this->fields($node, $this->binding->values[$pointer], $pointer), false];
        }

        if (property_exists($node, 'anyOf')) {
            return [$this->nonNull($node, $pointer), true];
        }

        [$node, $definition] = $this->resolve($node, $pointer);
        [$type, $nullable] = $this->type($node, $pointer);

        if ($type === 'object') {
            $this->bound($pointer, CodecKind::Object);
        }

        $value = match ($type) {
            'object' => CodecValue::object($this->object($node, $pointer, $definition ?? $pointer)),
            'array' => $this->list($node, $pointer),
            'string' => $this->string($node, $pointer),
            'integer' => $this->integer($node, $pointer),
            'boolean' => $this->boolean($pointer),
            default => throw $this->problem($pointer, sprintf('has the type "%s", which has no form in the codec', $type)),
        };

        return [$value, $nullable];
    }

    /**
     * The value that is not null of `anyOf` a value and null.
     *
     * @throws GenerationFailed
     */
    private function nonNull(stdClass $node, string $pointer): CodecValue
    {
        $options = $node->anyOf;

        if (! is_array($options) || count($options) !== 2 || $this->isNull($options[0]) === $this->isNull($options[1]) || property_exists($node, 'type') || property_exists($node, '$ref')) {
            throw $this->problem($pointer, 'has an "anyOf" that is not a value and {"type": "null"}, the one form of a nullable reference the codec reads');
        }

        $other = $this->isNull($options[0]) ? $options[1] : $options[0];

        if (! $other instanceof stdClass) {
            throw $this->problem($pointer, 'has an "anyOf" whose value is not a schema object');
        }

        [$value, $nullable] = $this->value($other, $pointer);

        if ($nullable) {
            throw $this->problem($pointer, 'is null twice in its "anyOf"');
        }

        return $value;
    }

    /**
     * The node a `$ref` names with the keywords of the referring node besides it, and the pointer of
     * the definition, or the node itself and null.
     *
     * @return array{stdClass, ?string}
     *
     * @throws GenerationFailed
     */
    private function resolve(stdClass $node, string $pointer): array
    {
        if (! property_exists($node, '$ref')) {
            return [$node, null];
        }

        $reference = $node->{'$ref'};

        if (! is_string($reference) || ! str_starts_with($reference, self::DEFS)) {
            throw $this->problem($pointer, 'has a "$ref" that is not to "#/$defs/<name>"');
        }

        $definitions = $this->document->{'$defs'} ?? null;
        $name = substr($reference, strlen(self::DEFS));
        $target = $definitions instanceof stdClass ? ($definitions->{$name} ?? null) : null;

        if (! $target instanceof stdClass || property_exists($target, '$ref')) {
            throw $this->problem($pointer, sprintf('refers to "%s", which is not a definition of the schema without a "$ref" of its own', $reference));
        }

        $merged = clone $target;

        foreach (get_object_vars($node) as $keyword => $value) {
            if ($keyword !== '$ref') {
                $merged->{$keyword} = $value;
            }
        }

        return [$merged, $reference];
    }

    /**
     * The type of a node and whether it may be null: its `type`, one type or one type and null, or
     * the type of the values of its `enum`.
     *
     * @return array{string, bool}
     *
     * @throws GenerationFailed
     */
    private function type(stdClass $node, string $pointer): array
    {
        $type = $node->type ?? null;

        if ($type === null && is_array($node->enum ?? null) && $node->enum !== []) {
            $types = array_unique(array_map(static fn (mixed $value): string => is_int($value) ? 'integer' : (is_string($value) ? 'string' : 'other'), $node->enum));

            return count($types) === 1 && $types !== ['other'] ? [array_first($types), false] : throw $this->problem($pointer, 'has an "enum" whose values are not all strings or all integers');
        }

        if (is_string($type)) {
            return [$type, false];
        }

        if (is_array($type) && count($type) === 2 && in_array('null', $type, true)) {
            $other = array_values(array_filter($type, static fn (mixed $name): bool => $name !== 'null'));

            if (count($other) === 1 && is_string($other[0])) {
                return [$other[0], true];
            }
        }

        throw $this->problem($pointer, 'has no "type", or one that is not a type or a type and "null"');
    }

    /**
     * @throws GenerationFailed
     */
    private function list(stdClass $node, string $pointer): CodecValue
    {
        $items = $node->items ?? null;

        if (! $items instanceof stdClass) {
            throw $this->problem($pointer, 'is an array without "items"');
        }

        [$item, $nullable] = $this->value($items, $pointer.'/items');

        if ($nullable) {
            throw $this->problem($pointer.'/items', 'may be null; an item of a list is never null');
        }

        return CodecValue::list($item, [
            ...$this->bound($pointer, CodecKind::List),
            ...$this->integerRule($node, 'minItems', ValidationRuleName::MinItems, $pointer),
            ...$this->integerRule($node, 'maxItems', ValidationRuleName::MaxItems, $pointer),
        ]);
    }

    /**
     * @throws GenerationFailed
     */
    private function string(stdClass $node, string $pointer): CodecValue
    {
        $binding = $this->binding->values[$pointer] ?? null;

        if ($binding instanceof ValueBinding) {
            return $this->boundString($node, $binding, $pointer);
        }

        if (property_exists($node, 'enum')) {
            throw $this->problem($pointer, 'has an "enum" that the binding binds to no PHP enum');
        }

        if (property_exists($node, 'pattern')) {
            throw $this->problem($pointer, 'is a string with a "pattern" that the binding binds to no class; the codec has no form for a pattern, so bind the value to a class whose constructor checks it');
        }

        if (property_exists($node, 'format')) {
            if ($node->format !== 'date-time' || property_exists($node, 'minLength') || property_exists($node, 'maxLength')) {
                throw $this->problem($pointer, 'has a "format" other than "date-time", or a date-time with a length');
            }

            return CodecValue::of(CodecKind::Datetime, [new ValidationRule(ValidationRuleName::Datetime)]);
        }

        return CodecValue::of(CodecKind::Text, [
            new ValidationRule(ValidationRuleName::String),
            ...$this->integerRule($node, 'minLength', ValidationRuleName::MinLength, $pointer),
            ...$this->integerRule($node, 'maxLength', ValidationRuleName::MaxLength, $pointer),
        ]);
    }

    /**
     * A string bound to a class, whose constructor checks it; an enum's values must be the schema's.
     *
     * @throws GenerationFailed
     */
    private function boundString(stdClass $node, ValueBinding $binding, string $pointer): CodecValue
    {
        if (property_exists($node, 'format')) {
            throw $this->problem($pointer, 'is bound to a class and has a "format"');
        }

        if ($binding->kind === CodecKind::Enum) {
            return $this->enum($node, $binding, $pointer);
        }

        if (! class_exists($binding->class)) {
            throw $this->problem($pointer, sprintf('is bound to %s, which is not a class', $binding->class));
        }

        $form = $this->form($node, $pointer);

        return match ($binding->kind) {
            CodecKind::Id => $this->valueClass($binding, $pointer, CodecValue::id($binding->class, $form), $binding->class),
            default => $this->valueClass($binding, $pointer, CodecValue::value($binding->class, $form), $binding->class),
        };
    }

    /**
     * @throws GenerationFailed
     */
    private function integer(stdClass $node, string $pointer): CodecValue
    {
        $binding = $this->binding->values[$pointer] ?? null;

        if ($binding instanceof ValueBinding) {
            return match ($binding->kind) {
                CodecKind::Enum => $this->enum($node, $binding, $pointer),
                CodecKind::Value => $this->integerValue($node, $binding, $pointer),
                default => throw $this->problem($pointer, 'is an integer bound to a class that is neither an enum nor a value'),
            };
        }

        if (property_exists($node, 'enum')) {
            throw $this->problem($pointer, 'has an "enum" that the binding binds to no PHP enum');
        }

        return CodecValue::of(CodecKind::Integer, [
            new ValidationRule(ValidationRuleName::Integer),
            ...$this->integerRule($node, 'minimum', ValidationRuleName::Min, $pointer),
            ...$this->integerRule($node, 'maximum', ValidationRuleName::Max, $pointer),
        ]);
    }

    /**
     * An integer bound to a value object of one integer, whose constructor checks it; the schema's
     * `minimum` and `maximum` are checked by the codec first.
     *
     * @throws GenerationFailed
     */
    private function integerValue(stdClass $node, ValueBinding $binding, string $pointer): CodecValue
    {
        if (property_exists($node, 'enum')) {
            throw $this->problem($pointer, 'is an integer bound to a value and has an "enum"');
        }

        if (! class_exists($binding->class)) {
            throw $this->problem($pointer, sprintf('is bound to %s, which is not a class', $binding->class));
        }

        $class = new ReflectionClass($binding->class);
        $constructor = $class->getConstructor();
        $parameters = $constructor?->getParameters() ?? [];

        if (! $constructor instanceof ReflectionMethod || ! $constructor->isPublic() || count($parameters) !== 1 || $this->named($parameters[0]->getType()) !== 'int'
            || ! $class->hasProperty('value') || ! $class->getProperty('value')->isPublic() || $this->named($class->getProperty('value')->getType()) !== 'int') {
            throw $this->problem($pointer, sprintf('is bound to %s as an integer value, which has no constructor of one int and public int $value', $binding->class));
        }

        return CodecValue::integerValue($binding->class, [
            ...$this->integerRule($node, 'minimum', ValidationRuleName::Min, $pointer),
            ...$this->integerRule($node, 'maximum', ValidationRuleName::Max, $pointer),
        ]);
    }

    /**
     * The fields of a revision of any type: a `$ref` to FieldValuesSchema::REFERENCE, with a
     * description at most, and each definition of FieldValuesSchema in the schema's `$defs` as it
     * is there, so every rule the schema states is one the codec checks.
     *
     * @throws GenerationFailed
     */
    private function fields(stdClass $node, ValueBinding $binding, string $pointer): CodecValue
    {
        if ($binding->class !== FieldValues::class) {
            throw $this->problem($pointer, sprintf('is bound as fields to %s; fields are %s', $binding->class, FieldValues::class));
        }

        if (($node->{'$ref'} ?? null) !== FieldValuesSchema::REFERENCE || array_diff(array_keys(get_object_vars($node)), ['$ref', 'description']) !== []) {
            throw $this->problem($pointer, sprintf('is bound as fields, but is not {"$ref": "%s"} with a description at most', FieldValuesSchema::REFERENCE));
        }

        $expected = json_decode(FieldValuesSchema::DEFINITIONS, false, 64, JSON_THROW_ON_ERROR);
        $definitions = $this->document->{'$defs'} ?? null;

        foreach (get_object_vars($expected) as $name => $definition) {
            $actual = $definitions instanceof stdClass ? ($definitions->{$name} ?? null) : null;

            if ($this->normalized($actual) !== $this->normalized($definition)) {
                throw $this->problem(self::DEFS.$name, 'is not the definition FieldValuesSchema gives the fields of a revision; copy it from there');
            }
        }

        return CodecValue::fields(FieldValues::class);
    }

    /**
     * A JSON value as canonical text: the keys of every object sorted.
     */
    private function normalized(mixed $value): string
    {
        return (string) json_encode($this->sorted($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sorted(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->sorted(...), $value);
        }

        if (! $value instanceof stdClass) {
            return $value;
        }

        $properties = get_object_vars($value);
        ksort($properties, SORT_STRING);

        return (object) array_map($this->sorted(...), $properties);
    }

    /**
     * @throws GenerationFailed
     */
    private function boolean(string $pointer): CodecValue
    {
        $this->bound($pointer, CodecKind::Boolean);

        return CodecValue::of(CodecKind::Boolean, [new ValidationRule(ValidationRuleName::Boolean)]);
    }

    /**
     * A bound backed enum. With an `enum`, its values must be the enum's case values in order; with
     * a `pattern` instead, every case value must match it and fit `maxLength`.
     *
     * @throws GenerationFailed
     */
    private function enum(stdClass $node, ValueBinding $binding, string $pointer): CodecValue
    {
        $class = $binding->class;

        if ($binding->kind !== CodecKind::Enum || ! is_subclass_of($class, BackedEnum::class)) {
            throw $this->problem($pointer, sprintf('is bound to %s as an enum, which is not a backed enum', $class));
        }

        $values = array_map(static fn (BackedEnum $case): int|string => $case->value, $class::cases());

        if (property_exists($node, 'enum')) {
            if ($node->enum !== $values) {
                throw $this->problem($pointer, sprintf('has the "enum" %s, but %s has the values %s in that order', $this->json($node->enum), $class, $this->json($values)));
            }

            return CodecValue::enum($class);
        }

        $pattern = $node->pattern ?? null;
        $maxLength = $node->maxLength ?? null;

        if (! is_string($pattern)) {
            throw $this->problem($pointer, 'is bound to an enum and has neither an "enum" nor a "pattern" its values match');
        }

        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/'.str_replace('/', '\/', $pattern).'/u', $value) !== 1 || (is_int($maxLength) && mb_strlen($value) > $maxLength)) {
                throw $this->problem($pointer, sprintf('has a "pattern" or "maxLength" that the value "%s" of %s breaks', $value, $class));
            }
        }

        return CodecValue::enum($class);
    }

    /**
     * An id or a value object of one string, checked for the methods the codec calls.
     *
     * @param  class-string  $name  the bound class
     *
     * @throws GenerationFailed
     */
    private function valueClass(ValueBinding $binding, string $pointer, CodecValue $value, string $name): CodecValue
    {
        $class = new ReflectionClass($name);

        $usable = $binding->kind === CodecKind::Id
            ? $this->hasStaticString($class, 'fromString') && $this->returnsString($class, 'toString')
            : $this->takesOneString($class) && $class->hasProperty('value') && $class->getProperty('value')->isPublic() && $this->named($class->getProperty('value')->getType()) === 'string';

        if (! $usable) {
            throw $this->problem($pointer, $binding->kind === CodecKind::Id
                ? sprintf('is bound to %s as an id, which has no public static fromString(string) and public toString(): string', $binding->class)
                : sprintf('is bound to %s as a value, which has no constructor of one string and public string $value', $binding->class));
        }

        return $value;
    }

    /**
     * The form of a bound string: its `pattern`, `minLength` and `maxLength`, which the bound class
     * checks in PHP and the TypeScript validator checks from the schema.
     *
     * @throws GenerationFailed
     */
    private function form(stdClass $node, string $pointer): ?StringForm
    {
        $pattern = $node->pattern ?? null;

        if ($pattern !== null && ! is_string($pattern)) {
            throw $this->problem($pointer, 'has a "pattern" that is not a string');
        }

        $minLength = $this->integerRule($node, 'minLength', ValidationRuleName::MinLength, $pointer)[0]->arguments[0] ?? null;
        $maxLength = $this->integerRule($node, 'maxLength', ValidationRuleName::MaxLength, $pointer)[0]->arguments[0] ?? null;

        return $pattern === null && $minLength === null && $maxLength === null
            ? null
            : new StringForm($pattern, $minLength === null ? null : (int) $minLength, $maxLength === null ? null : (int) $maxLength);
    }

    /**
     * Refuses a value binding on a place whose kind cannot be bound.
     *
     * @return list<ValidationRule>
     *
     * @throws GenerationFailed
     */
    private function bound(string $pointer, CodecKind $kind): array
    {
        if (array_key_exists($pointer, $this->binding->values)) {
            throw $this->problem($pointer, sprintf('is a value of the kind %s, which the binding cannot bind to a class', $kind->value));
        }

        return [];
    }

    /**
     * The rule of an integer keyword, or none when the node does not have it.
     *
     * @return list<ValidationRule>
     *
     * @throws GenerationFailed
     */
    private function integerRule(stdClass $node, string $keyword, ValidationRuleName $rule, string $pointer): array
    {
        if (! property_exists($node, $keyword)) {
            return [];
        }

        if (! is_int($node->{$keyword}) || ($node->{$keyword} < 0 && ! in_array($keyword, ['minimum', 'maximum'], true))) {
            throw $this->problem($pointer, sprintf('has a "%s" that is not an integer of 0 or more', $keyword));
        }

        return [new ValidationRule($rule, [(string) $node->{$keyword}])];
    }

    /**
     * The PHP expression of a default, for a value of its kind.
     *
     * @throws GenerationFailed
     */
    private function defaultExpression(mixed $default, CodecValue $value, bool $nullable, string $pointer): string
    {
        $object = $value->object;

        return match (true) {
            $default === null && $nullable => 'null',
            is_bool($default) && $value->kind === CodecKind::Boolean => $default ? 'true' : 'false',
            is_int($default) && $value->kind === CodecKind::Integer => (string) $default,
            is_string($default) && $value->kind === CodecKind::Text => PhpSource::string($default),
            $default === [] && $value->kind === CodecKind::List => '[]',
            $value->kind === CodecKind::Enum && (is_string($default) || is_int($default)) => $this->enumCase($value, $default, $pointer),
            $default instanceof stdClass && get_object_vars($default) === [] && $object instanceof CodecObject && $this->emptyObject($object, $pointer) => 'new '.$object->className,
            default => throw $this->problem($pointer, sprintf('has the default %s, which is not a value of the property or has no form in PHP', $this->json($default))),
        };
    }

    /**
     * @throws GenerationFailed
     */
    private function enumCase(CodecValue $value, int|string $default, string $pointer): string
    {
        $class = (string) $value->class;

        foreach (is_subclass_of($class, BackedEnum::class) ? $class::cases() : [] as $case) {
            if ($case->value === $default) {
                return PhpSource::shortName($class).'::'.$case->name;
            }
        }

        throw $this->problem($pointer, sprintf('has the default %s, which is not a value of %s', $this->json($default), $class));
    }

    /**
     * Whether `{}` is the object: every property has a default, and so does every argument of the
     * class's constructor, so the codec writes the default as `new` without arguments.
     *
     * @throws GenerationFailed
     */
    private function emptyObject(CodecObject $object, string $pointer): bool
    {
        if (array_any($object->properties, static fn (CodecProperty $property): bool => $property->required)) {
            throw $this->problem($pointer, 'has the default {}, but its object has required keys');
        }

        return true;
    }

    /**
     * Holds a bound object's class to the object: a public constructor with an argument per
     * property, by name, of the property's type and nullability, with the property's default, and
     * a public property of each name to read the value from.
     *
     * @return list<string> the names of the constructor's arguments in their order
     *
     * @throws GenerationFailed
     */
    private function assertClass(CodecObject $object, string $pointer, string $definition): array
    {
        $name = $object->class ?? throw $this->problem($pointer, 'is bound to no class');
        $class = new ReflectionClass($name);

        $constructor = $class->getConstructor();

        if (! $constructor instanceof ReflectionMethod || ! $constructor->isPublic()) {
            throw $this->problem($pointer, sprintf('is bound to %s, which has no public constructor', $name));
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        $properties = array_map(static fn (CodecProperty $property): string => $property->name, $object->properties);
        $missing = array_diff(array_keys($parameters), $properties);
        $extra = array_diff($properties, array_keys($parameters));

        if ($missing !== [] || $extra !== []) {
            throw $this->problem($pointer, sprintf(
                'is bound to %s, whose constructor takes %s, but the object has the properties %s',
                $name,
                implode(', ', array_keys($parameters)),
                implode(', ', $properties),
            ));
        }

        foreach ($object->properties as $property) {
            $this->assertParameter($class, $parameters[$property->name], $property, $definition.'/properties/'.$property->key);
        }

        return array_keys($parameters);
    }

    /**
     * @param  ReflectionClass<object>  $class
     *
     * @throws GenerationFailed
     */
    private function assertParameter(ReflectionClass $class, ReflectionParameter $parameter, CodecProperty $property, string $pointer): void
    {
        $where = sprintf('%s::__construct($%s)', $class->getName(), $parameter->getName());
        $expected = $this->phpType($property->value);
        $type = $parameter->getType();

        if ($parameter->isVariadic() || ! $type instanceof ReflectionNamedType || $type->getName() !== $expected) {
            throw $this->problem($pointer, sprintf('is the key "%s", but %s is not of the type %s', $property->key, $where, $expected));
        }

        if ($type->allowsNull() !== $property->mayBeNull()) {
            throw $this->problem($pointer, sprintf('is the key "%s", which %s null, but %s %s', $property->key, $property->mayBeNull() ? 'may be' : 'is never', $where, $type->allowsNull() ? 'allows it' : 'does not'));
        }

        if (! $class->hasProperty($parameter->getName()) || ! $class->getProperty($parameter->getName())->isPublic()) {
            throw $this->problem($pointer, sprintf('is the key "%s", but %s has no public property $%s to write it from', $property->key, $class->getName(), $parameter->getName()));
        }

        if ($property->default !== null && ! $parameter->isDefaultValueAvailable()) {
            throw $this->problem($pointer, sprintf('has a default, but %s has none', $where));
        }

        if ($property->default === null || ! $parameter->isDefaultValueAvailable()) {
            return;
        }

        $default = $parameter->getDefaultValue();
        $written = match (true) {
            $default === null => 'null',
            is_bool($default) => $default ? 'true' : 'false',
            is_int($default) => (string) $default,
            is_string($default) => PhpSource::string($default),
            $default === [] => '[]',
            $default instanceof BackedEnum => PhpSource::shortName($default::class).'::'.$default->name,
            is_object($default) => 'new '.PhpSource::shortName($default::class),
            default => null,
        };

        if ($written !== $property->default) {
            throw $this->problem($pointer, sprintf('has the default %s, but the default of %s is %s', $property->default, $where, $written ?? 'another value'));
        }
    }

    /**
     * The native PHP type of a constructor argument that takes a value of the kind.
     */
    private function phpType(CodecValue $value): string
    {
        return match ($value->kind) {
            CodecKind::Text, CodecKind::Decimal, CodecKind::Choice => 'string',
            CodecKind::Integer => 'int',
            CodecKind::Boolean => 'bool',
            CodecKind::Date, CodecKind::Datetime => DateTimeImmutable::class,
            CodecKind::List, CodecKind::PortableText => 'array',
            CodecKind::Object => (string) $value->object?->class,
            CodecKind::Id, CodecKind::Enum, CodecKind::Value, CodecKind::IntegerValue, CodecKind::Fields => (string) $value->class,
        };
    }

    /**
     * Refuses a keyword the codec has no form for.
     *
     * @param  list<string>  $extra
     *
     * @throws GenerationFailed
     */
    private function assertKeywords(stdClass $node, string $pointer, array $extra = []): void
    {
        foreach (array_keys(get_object_vars($node)) as $keyword) {
            if (! in_array($keyword, [...self::KEYWORDS, ...$extra], true)) {
                throw $this->problem($pointer, sprintf('has the keyword "%s", which the codec has no form for', $keyword));
            }
        }
    }

    /**
     * Refuses a binding no place of the schema uses, so none is left behind after a change.
     *
     * @throws GenerationFailed
     */
    private function assertBindingsUsed(): void
    {
        $used = $this->used();

        foreach ([...array_keys($this->binding->objects), ...array_keys($this->binding->values), ...array_keys($this->binding->names)] as $pointer) {
            if (! in_array($pointer, $used, true)) {
                throw $this->problem($pointer, 'is in the binding, but is no place of the schema that can be bound');
            }
        }
    }

    /**
     * Every pointer of the schema that names an object's definition, a property or a list's items.
     *
     * @return list<string>
     */
    private function used(): array
    {
        $pointers = ['#', ...$this->places($this->document, '#')];
        $definitions = $this->document->{'$defs'} ?? null;

        foreach ($definitions instanceof stdClass ? get_object_vars($definitions) : [] as $name => $definition) {
            if ($definition instanceof stdClass) {
                $pointers = [...$pointers, self::DEFS.$name, ...$this->places($definition, self::DEFS.$name)];
            }
        }

        return $pointers;
    }

    /**
     * @return list<string>
     */
    private function places(stdClass $node, string $pointer): array
    {
        $places = [];
        $properties = $node->properties ?? null;

        foreach ($properties instanceof stdClass ? get_object_vars($properties) : [] as $key => $property) {
            $at = $pointer.'/properties/'.$key;
            $places[] = $at;

            if ($property instanceof stdClass && ($property->items ?? null) instanceof stdClass) {
                $places[] = $at.'/items';
            }
        }

        return $places;
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function hasStaticString(ReflectionClass $class, string $method): bool
    {
        if (! $class->hasMethod($method)) {
            return false;
        }

        $reflection = $class->getMethod($method);
        $parameters = $reflection->getParameters();

        return $reflection->isPublic() && $reflection->isStatic() && count($parameters) === 1 && $this->named($parameters[0]->getType()) === 'string';
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function returnsString(ReflectionClass $class, string $method): bool
    {
        return $class->hasMethod($method)
            && $class->getMethod($method)->isPublic()
            && ! $class->getMethod($method)->isStatic()
            && $this->named($class->getMethod($method)->getReturnType()) === 'string';
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function takesOneString(ReflectionClass $class): bool
    {
        $constructor = $class->getConstructor();
        $parameters = $constructor?->getParameters() ?? [];

        return $constructor instanceof ReflectionMethod && $constructor->isPublic() && count($parameters) === 1 && $this->named($parameters[0]->getType()) === 'string';
    }

    private function named(mixed $type): ?string
    {
        return $type instanceof ReflectionNamedType && ! $type->allowsNull() ? $type->getName() : null;
    }

    /**
     * The lines of the codec's PHPDoc.
     *
     * @return list<string>
     */
    private static function summary(stdClass $document, SchemaBinding $binding, CodecObject $root): array
    {
        return [
            sprintf('The JSON codec of %s (GUARDRAILS 2.2): %s as %s.', self::text($document->title ?? $binding->schema), $root->className, $binding->schema),
            '',
            sprintf('Generated by composer generate:protocol from %s in the %s module.', $binding->schema, explode('/', $binding->directory)[1] ?? $binding->directory),
            'Do not edit this file: change the schema and run composer generate:protocol.',
        ];
    }

    private function isNull(mixed $option): bool
    {
        return $option instanceof stdClass && get_object_vars($option) === ['type' => 'null'];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function problem(string $pointer, string $message): GenerationFailed
    {
        return self::invalid($this->binding, $pointer, $message);
    }

    private static function invalid(SchemaBinding $binding, string $pointer, string $message, ?Throwable $previous = null): GenerationFailed
    {
        return GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('%s %s: %s.', $binding->schema, $pointer, $message), $previous);
    }
}
