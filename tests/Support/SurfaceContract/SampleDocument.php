<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Codecs\JsonSchema;
use LogicException;
use stdClass;

/**
 * The smallest document a command's JSON Schema accepts, made from the schema alone, so a new
 * command gets its surface contract tests without a hand-written fixture. It holds every required
 * property and no other: null where the schema allows null, the minimum of an integer (or 1), the
 * first value of an enum, false, minItems items of a list, and for a string a date-time, or the
 * first of CANDIDATES that matches its pattern, or minLength letters. Each string that is sampled
 * from a candidate gets the next number, so two ids of one document differ.
 *
 * It reads the keywords the command codecs' schemas use (JsonSchemaContract): `$ref` to
 * `#/$defs/<name>`, `anyOf`, `type`, `enum`, `format`, `pattern`, `minimum`, `minLength`,
 * `minItems`, `items`, `properties` and `required`. A pattern no candidate matches is a
 * LogicException that names it: add a candidate for it.
 */
final class SampleDocument
{
    /**
     * The strings tried for a string with a pattern, in order; %d is the string's number.
     *
     * @var list<string>
     */
    private const array CANDIDATES = [
        '0199a3c1-2b4d-7e5f-8a6b-%012d',
        'da',
        'fixture%d',
    ];

    private const string DATE_TIME = '2026-03-09T10:00:00+00:00';

    private int $strings = 0;

    private function __construct(private readonly stdClass $schema) {}

    /**
     * The sample of the schema, as a decoded JSON object.
     */
    public static function of(JsonSchema $schema): stdClass
    {
        $document = json_decode($schema->json, false, JsonSchema::DEPTH, JSON_THROW_ON_ERROR);

        if (! $document instanceof stdClass) {
            throw new LogicException('The schema is not a JSON object.');
        }

        $sample = new self($document)->value($document, '#');

        return $sample instanceof stdClass ? $sample : throw new LogicException('The schema does not describe an object.');
    }

    /**
     * The names of the schema's required properties, in the order it lists them.
     *
     * @return list<string>
     */
    public static function required(JsonSchema $schema): array
    {
        $document = json_decode($schema->json, false, JsonSchema::DEPTH, JSON_THROW_ON_ERROR);
        $required = $document instanceof stdClass ? ($document->required ?? []) : [];

        return is_array($required) ? array_values(array_filter($required, is_string(...))) : [];
    }

    /**
     * The JSON text of a document.
     */
    public static function json(stdClass $document): string
    {
        return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function value(stdClass $node, string $at): mixed
    {
        $reference = $node->{'$ref'} ?? null;

        if (is_string($reference)) {
            return $this->value($this->definition($reference), $reference);
        }

        $anyOf = $node->anyOf ?? null;

        if (is_array($anyOf)) {
            $alternatives = array_values(array_filter($anyOf, static fn (mixed $alternative): bool => $alternative instanceof stdClass));

            foreach ($alternatives as $alternative) {
                if ($this->types($alternative) === ['null']) {
                    return null;
                }
            }

            return $alternatives === [] ? throw new LogicException(sprintf('%s has an anyOf without schemas.', $at)) : $this->value($alternatives[0], $at);
        }

        $enum = $node->enum ?? null;

        if (is_array($enum) && $enum !== []) {
            return $enum[0];
        }

        $types = $this->types($node);

        if (in_array('null', $types, true)) {
            return null;
        }

        return match ($types[0] ?? null) {
            'object' => $this->object($node, $at),
            'array' => $this->list($node, $at),
            'string' => $this->string($node, $at),
            'integer' => is_int($node->minimum ?? null) ? $node->minimum : 1,
            'boolean' => false,
            default => throw new LogicException(sprintf('%s has no type the sample knows.', $at)),
        };
    }

    private function object(stdClass $node, string $at): stdClass
    {
        $object = new stdClass;
        $properties = $node->properties ?? new stdClass;
        $required = $node->required ?? [];

        if (! $properties instanceof stdClass || ! is_array($required)) {
            throw new LogicException(sprintf('%s has properties or required it cannot read.', $at));
        }

        foreach ($required as $name) {
            $property = is_string($name) ? ($properties->{$name} ?? null) : null;

            if (! is_string($name) || ! $property instanceof stdClass) {
                throw new LogicException(sprintf('%s requires a property it does not describe.', $at));
            }

            $object->{$name} = $this->value($property, $at.'/properties/'.$name);
        }

        return $object;
    }

    /**
     * @return list<mixed>
     */
    private function list(stdClass $node, string $at): array
    {
        $count = is_int($node->minItems ?? null) ? $node->minItems : 0;
        $items = $node->items ?? null;

        if ($count === 0) {
            return [];
        }

        if (! $items instanceof stdClass) {
            throw new LogicException(sprintf('%s needs items and does not describe them.', $at));
        }

        $list = [];

        for ($index = 0; $index < $count; $index++) {
            $list[] = $this->value($items, $at.'/items');
        }

        return $list;
    }

    private function string(stdClass $node, string $at): string
    {
        if (($node->format ?? null) === 'date-time') {
            return self::DATE_TIME;
        }

        $pattern = $node->pattern ?? null;

        if (! is_string($pattern)) {
            return str_repeat('a', max(1, is_int($node->minLength ?? null) ? $node->minLength : 1));
        }

        $number = ++$this->strings;

        foreach (self::CANDIDATES as $candidate) {
            $string = sprintf($candidate, $number);

            if (preg_match('/'.str_replace('/', '\/', $pattern).'/u', $string) === 1) {
                return $string;
            }
        }

        throw new LogicException(sprintf('No candidate string of %s matches the pattern %s at %s. Add one that does.', self::class, $pattern, $at));
    }

    /**
     * @return list<string>
     */
    private function types(stdClass $node): array
    {
        $type = $node->type ?? [];

        return array_values(array_filter(is_array($type) ? $type : [$type], is_string(...)));
    }

    private function definition(string $reference): stdClass
    {
        $name = str_starts_with($reference, '#/$defs/') ? substr($reference, 8) : throw new LogicException(sprintf('The reference %s is not to #/$defs/.', $reference));
        $definitions = $this->schema->{'$defs'} ?? null;
        $definition = $definitions instanceof stdClass ? ($definitions->{$name} ?? null) : null;

        return $definition instanceof stdClass ? $definition : throw new LogicException(sprintf('The schema has no definition %s.', $reference));
    }
}
