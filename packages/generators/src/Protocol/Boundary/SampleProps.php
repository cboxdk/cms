<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Protocol\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ArrayLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\BooleanLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\NumberLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ObjectLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Property;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\StringLiteral;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use JsonException;
use stdClass;

/**
 * Sample props of a panel point, made from the point's JSON Schema alone (PRD 13.4): a document
 * the schema accepts with every property present, so a story, a test of a contribution or an
 * addon author sees each member with a value, and the same schema always gives the same sample.
 *
 * Each value is the first of the node's `examples` when it has some, else the first value of its
 * `enum`, else by its type: an object with every property, sorted by key; a list with `minItems`
 * items, or one when it may hold one; for a nullable value, a value of its other type; a date-time
 * at DATE_TIME; a string of SAMPLE_TEXT's letters cut or padded to its lengths; an integer at its
 * `minimum`, or 1 within its `maximum`; and true. A string with a `pattern` has no such default:
 * its schema gives `examples`, so the sample of an id or a value object is one its class accepts,
 * and a pattern without them is refused with generate_schema_invalid.
 *
 * It reads the keywords JsonSchemaContract reads; the codec and the schema hold the sample to the
 * contract, and the Codecs suite validates every point's sample against its schema.
 */
#[Internal]
final readonly class SampleProps
{
    /** The instant of a sampled date-time, in the form the codecs write. */
    public const string DATE_TIME = '2026-01-01T00:00:00.000000Z';

    /** The letters of a sampled string. */
    public const string SAMPLE_TEXT = 'sample';

    private const string DEFS = '#/$defs/';

    private const int DEPTH = 64;

    private function __construct(
        private stdClass $schema,
        private string $name,
    ) {}

    /**
     * The sample of the schema $json, a decoded JSON object.
     *
     * @param  string  $name  the schema, for the problem a refusal reports, such as its path
     *
     * @throws GenerationFailed with generate_schema_invalid
     */
    public static function of(string $json, string $name): stdClass
    {
        try {
            $schema = json_decode($json, false, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('The schema %s is not well-formed JSON: %s', $name, $exception->getMessage()), $exception);
        }

        if (! $schema instanceof stdClass) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('The schema %s is not a JSON object.', $name));
        }

        $sample = new self($schema, $name)->value($schema, '#');

        return $sample instanceof stdClass
            ? $sample
            : throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('The schema %s does not describe an object, so it has no sample props.', $name));
    }

    /**
     * The sample's JSON text: no whitespace, slashes and Unicode unescaped, as the codecs write.
     */
    public static function json(stdClass $sample): string
    {
        return json_encode($sample, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * The sample as a TypeScript literal, for the generated module of the point.
     *
     * @throws GenerationFailed with generate_invalid_output for a value a literal cannot print
     */
    public static function literal(mixed $value): Literal
    {
        return match (true) {
            $value instanceof stdClass => new ObjectLiteral(array_map(
                static fn (string $key): Property => new Property($key, self::literal($value->{$key})),
                array_map(strval(...), array_keys(get_object_vars($value))),
            )),
            is_array($value) => new ArrayLiteral(array_values(array_map(self::literal(...), $value))),
            is_string($value) => new StringLiteral($value),
            is_int($value) => NumberLiteral::of($value),
            is_bool($value) => new BooleanLiteral($value),
            default => throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('A sample holds %s, which has no TypeScript literal.', get_debug_type($value))),
        };
    }

    /**
     * @throws GenerationFailed
     */
    private function value(stdClass $node, string $at): mixed
    {
        $examples = $node->examples ?? null;

        if (is_array($examples) && $examples !== []) {
            return $examples[0];
        }

        $reference = $node->{'$ref'} ?? null;

        if (is_string($reference)) {
            return $this->value($this->definition($reference, $at), $reference);
        }

        $anyOf = $node->anyOf ?? null;

        if (is_array($anyOf)) {
            foreach ($anyOf as $alternative) {
                if ($alternative instanceof stdClass && $this->types($alternative) !== ['null']) {
                    return $this->value($alternative, $at.'/anyOf');
                }
            }

            throw $this->problem($at, 'has an anyOf without a value other than null');
        }

        $enum = $node->enum ?? null;

        if (is_array($enum) && $enum !== []) {
            return $enum[0];
        }

        $types = array_values(array_diff($this->types($node), ['null']));

        return match ($types[0] ?? null) {
            'object' => $this->object($node, $at),
            'array' => $this->list($node, $at),
            'string' => $this->string($node, $at),
            'integer' => $this->integer($node),
            'boolean' => true,
            default => throw $this->problem($at, 'has no type a sample can be made of'),
        };
    }

    /**
     * @throws GenerationFailed
     */
    private function object(stdClass $node, string $at): stdClass
    {
        $properties = $node->properties ?? new stdClass;

        if (! $properties instanceof stdClass) {
            throw $this->problem($at, 'has properties that are not an object');
        }

        $keys = array_map(strval(...), array_keys(get_object_vars($properties)));
        sort($keys, SORT_STRING);
        $object = new stdClass;

        foreach ($keys as $key) {
            $property = $properties->{$key};

            if (! $property instanceof stdClass) {
                throw $this->problem($at.'/properties/'.$key, 'is not a schema');
            }

            $object->{$key} = $this->value($property, $at.'/properties/'.$key);
        }

        return $object;
    }

    /**
     * @return list<mixed>
     *
     * @throws GenerationFailed
     */
    private function list(stdClass $node, string $at): array
    {
        $minimum = is_int($node->minItems ?? null) ? $node->minItems : 0;
        $maximum = is_int($node->maxItems ?? null) ? $node->maxItems : null;
        $count = $maximum === 0 ? 0 : max(1, $minimum);
        $items = $node->items ?? null;

        if ($count === 0) {
            return [];
        }

        if (! $items instanceof stdClass) {
            throw $this->problem($at, 'is a list that does not describe its items');
        }

        $item = $this->value($items, $at.'/items');

        return array_fill(0, $count, $item);
    }

    /**
     * @throws GenerationFailed
     */
    private function string(stdClass $node, string $at): string
    {
        if (($node->format ?? null) === 'date-time') {
            return self::DATE_TIME;
        }

        if (property_exists($node, 'pattern')) {
            throw $this->problem($at, 'is a string with a "pattern" and no "examples"; give the first example a value its class accepts, which the sample props use');
        }

        $minimum = is_int($node->minLength ?? null) ? $node->minLength : 0;
        $maximum = is_int($node->maxLength ?? null) ? $node->maxLength : null;
        $text = str_pad(self::SAMPLE_TEXT, $minimum, 'a');

        return $maximum === null ? $text : substr($text, 0, $maximum);
    }

    private function integer(stdClass $node): int
    {
        $value = is_int($node->minimum ?? null) ? $node->minimum : 1;
        $maximum = $node->maximum ?? null;

        return is_int($maximum) && $value > $maximum ? $maximum : $value;
    }

    /**
     * @return list<string>
     */
    private function types(stdClass $node): array
    {
        $type = $node->type ?? null;

        if (is_string($type)) {
            return [$type];
        }

        return is_array($type) ? array_values(array_filter($type, is_string(...))) : [];
    }

    /**
     * @throws GenerationFailed
     */
    private function definition(string $reference, string $at): stdClass
    {
        $definitions = $this->schema->{'$defs'} ?? null;
        $definition = str_starts_with($reference, self::DEFS) && $definitions instanceof stdClass
            ? ($definitions->{substr($reference, strlen(self::DEFS))} ?? null)
            : null;

        return $definition instanceof stdClass ? $definition : throw $this->problem($at, sprintf('refers to %s, which the schema does not define', $reference));
    }

    private function problem(string $at, string $message): GenerationFailed
    {
        return GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf('The schema %s at %s %s.', $this->name, $at, $message));
    }
}
