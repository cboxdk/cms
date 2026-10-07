<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Panel;

use LogicException;
use stdClass;

/**
 * The props of a panel point with one rule of its JSON Schema broken, one document per rule and
 * place, made from the schema and the point's sample props alone (PRD 13.4): for every member a
 * value of the wrong type, null where it is not nullable, the key left out where it is required,
 * and for its rules a value past each of them: an enum's unknown value, a string shorter than its
 * minLength, longer than its maxLength, off its pattern or not a date-time, an integer that is a
 * fraction or past its minimum or maximum, a list with too few or too many items, and each rule of
 * the first item; and every object with a key the schema does not have, except a document of
 * another contract, an object without properties, which takes any keys. The cases of an object are
 * those of its members, and of the members of the objects they hold.
 *
 * It reads the keywords the point schemas use (JsonSchemaContract): `$ref` to `#/$defs/<name>`,
 * `anyOf` of a value and null, `type`, `enum`, `format`, `pattern`, `minimum`, `maximum`,
 * `minLength`, `maxLength`, `minItems`, `maxItems`, `items`, `properties` and `required`.
 */
final class PointViolations
{
    private const string DEFS = '#/$defs/';

    /** @var array<string, string> */
    private array $cases = [];

    private function __construct(
        private readonly stdClass $schema,
        private readonly stdClass $sample,
    ) {}

    /**
     * Each broken document, by a description of the rule and place it breaks.
     *
     * @return array<string, string>
     */
    public static function of(string $schema, stdClass $sample): array
    {
        $document = json_decode($schema, false, 64, JSON_THROW_ON_ERROR);

        if (! $document instanceof stdClass) {
            throw new LogicException('The schema is not a JSON object.');
        }

        $violations = new self($document, $sample);
        $violations->object($document, $sample, []);

        return $violations->cases;
    }

    /**
     * @param  list<int|string>  $at
     */
    private function object(stdClass $node, stdClass $value, array $at): void
    {
        $properties = $node->properties ?? new stdClass;
        $required = $node->required ?? [];

        if (! $properties instanceof stdClass || ! is_array($required)) {
            throw new LogicException('An object schema without properties or required it can read.');
        }

        $this->plant($at, 'a key the schema does not have', static function (mixed $object): mixed {
            if ($object instanceof stdClass) {
                $object = clone $object;
                $object->planted = true;
            }

            return $object;
        });

        foreach (get_object_vars($properties) as $key => $property) {
            $key = (string) $key;

            if (! $property instanceof stdClass) {
                throw new LogicException(sprintf('The member %s has no schema.', $key));
            }

            if (in_array($key, $required, true)) {
                $this->plant($at, $key.' left out', static function (mixed $object) use ($key): mixed {
                    if ($object instanceof stdClass) {
                        $object = clone $object;
                        unset($object->{$key});
                    }

                    return $object;
                });
            }

            $this->value($property, $value->{$key} ?? null, [...$at, $key]);
        }
    }

    /**
     * @param  list<int|string>  $at
     */
    private function value(stdClass $node, mixed $value, array $at): void
    {
        [$node, $nullable] = $this->resolve($node);
        $place = $this->place($at);

        if (! $nullable) {
            $this->set($at, $place.' null', null);
        }

        $enum = $node->enum ?? null;

        if (is_array($enum)) {
            $this->set($at, $place.' not one of its values', 'planted_unknown');
            $this->set($at, $place.' not a string', 7);

            return;
        }

        $type = $this->type($node);

        match ($type) {
            'object' => $this->objectValue($node, $value, $at, $place),
            'array' => $this->list($node, $value, $at, $place),
            'string' => $this->string($node, $at, $place),
            'integer' => $this->integer($node, $at, $place),
            'boolean' => $this->set($at, $place.' not a boolean', 'yes'),
            default => throw new LogicException(sprintf('%s has no type the violations know.', $place)),
        };
    }

    /**
     * @param  list<int|string>  $at
     */
    private function objectValue(stdClass $node, mixed $value, array $at, string $place): void
    {
        $this->set($at, $place.' not an object', 'planted');

        // A document of another contract, an object without properties of its own, takes any keys:
        // the contract's own codec and validator check them, so only a value that is no object
        // breaks the rule here.
        if ($value instanceof stdClass && isset($node->properties)) {
            $this->object($node, $value, $at);
        }
    }

    /**
     * @param  list<int|string>  $at
     */
    private function list(stdClass $node, mixed $value, array $at, string $place): void
    {
        $this->set($at, $place.' not a list', 'planted');
        $items = is_array($value) ? $value : [];
        $first = $items[0] ?? null;

        if (is_int($node->maxItems ?? null)) {
            $this->set($at, $place.' over maxItems', array_fill(0, $node->maxItems + 1, $first));
        }

        if (is_int($node->minItems ?? null) && $node->minItems > 0) {
            $this->set($at, $place.' under minItems', array_slice($items, 0, $node->minItems - 1));
        }

        $item = $node->items ?? null;

        if ($item instanceof stdClass && $items !== []) {
            $this->value($item, $first, [...$at, 0]);
        }
    }

    /**
     * @param  list<int|string>  $at
     */
    private function string(stdClass $node, array $at, string $place): void
    {
        $this->set($at, $place.' not a string', 7);

        if (($node->format ?? null) === 'date-time') {
            $this->set($at, $place.' not a date-time', 'yesterday');
        }

        if (is_string($node->pattern ?? null)) {
            $this->set($at, $place.' off its pattern', '!');
        }

        if (is_int($node->minLength ?? null) && $node->minLength > 0) {
            $this->set($at, $place.' under minLength', str_repeat('a', $node->minLength - 1));
        }

        if (is_int($node->maxLength ?? null)) {
            $this->set($at, $place.' over maxLength', str_repeat('a', $node->maxLength + 1));
        }
    }

    /**
     * @param  list<int|string>  $at
     */
    private function integer(stdClass $node, array $at, string $place): void
    {
        $this->set($at, $place.' not an integer', '1');
        $this->set($at, $place.' a fraction', 1.5);

        if (is_int($node->minimum ?? null)) {
            $this->set($at, $place.' under minimum', $node->minimum - 1);
        }

        if (is_int($node->maximum ?? null)) {
            $this->set($at, $place.' over maximum', $node->maximum + 1);
        }
    }

    /**
     * The node a `$ref` or an `anyOf` with null names, and whether null is allowed.
     *
     * @return array{stdClass, bool}
     */
    private function resolve(stdClass $node): array
    {
        $nullable = false;

        while (true) {
            $reference = $node->{'$ref'} ?? null;
            $anyOf = $node->anyOf ?? null;

            if (is_string($reference) && str_starts_with($reference, self::DEFS)) {
                $definitions = $this->schema->{'$defs'} ?? null;
                $definition = $definitions instanceof stdClass ? ($definitions->{substr($reference, strlen(self::DEFS))} ?? null) : null;
                $node = $definition instanceof stdClass ? $definition : throw new LogicException('No definition '.$reference.'.');

                continue;
            }

            if (is_array($anyOf)) {
                $other = null;

                foreach ($anyOf as $alternative) {
                    if ($alternative instanceof stdClass && $this->types($alternative) === ['null']) {
                        $nullable = true;
                    } elseif ($alternative instanceof stdClass) {
                        $other = $alternative;
                    }
                }

                $node = $other ?? throw new LogicException('An anyOf without a value other than null.');

                continue;
            }

            return [$node, $nullable || in_array('null', $this->types($node), true)];
        }
    }

    private function type(stdClass $node): ?string
    {
        return array_first(array_diff($this->types($node), ['null'])) ?? null;
    }

    /**
     * @return list<string>
     */
    private function types(stdClass $node): array
    {
        $type = $node->type ?? null;

        return is_string($type) ? [$type] : (is_array($type) ? array_values(array_filter($type, is_string(...))) : []);
    }

    /**
     * @param  list<int|string>  $at
     */
    private function set(array $at, string $name, mixed $wrong): void
    {
        $this->plant($at, $name, static fn (): mixed => $wrong);
    }

    /**
     * @param  list<int|string>  $at
     * @param  callable(mixed): mixed  $change
     */
    private function plant(array $at, string $name, callable $change): void
    {
        $document = json_decode(json_encode($this->sample, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        $name = ($at === [] || str_starts_with($name, $this->place($at)) ? '' : $this->place($at).': ').$name;
        $this->cases[$name] = json_encode(self::changed($document, $at, $change), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<int|string>  $at
     * @param  callable(mixed): mixed  $change
     */
    private static function changed(mixed $node, array $at, callable $change): mixed
    {
        if ($at === []) {
            return $change($node);
        }

        $segment = array_shift($at);

        if (is_int($segment) && is_array($node)) {
            $node[$segment] = self::changed($node[$segment] ?? null, $at, $change);

            return $node;
        }

        if (is_string($segment) && $node instanceof stdClass) {
            $node->{$segment} = self::changed($node->{$segment} ?? null, $at, $change);

            return $node;
        }

        throw new LogicException('The sample has no place to plant a value at.');
    }

    /**
     * @param  list<int|string>  $at
     */
    private function place(array $at): string
    {
        $place = '';

        foreach ($at as $segment) {
            $place .= is_int($segment) ? '['.$segment.']' : ($place === '' ? $segment : '.'.$segment);
        }

        return $place === '' ? 'the props' : $place;
    }
}
