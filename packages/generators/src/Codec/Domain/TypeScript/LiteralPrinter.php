<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\TypeScript;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Prints a literal the way Prettier prints it with the shared configuration (printWidth 100, two
 * spaces, single quotes, trailing commas), so `prettier --check` accepts a generated module
 * unchanged:
 *
 * - an object or an array stays on one line when it fits with what follows it up to the next
 *   place Prettier may break, and otherwise gets one property or item per line with a trailing
 *   comma;
 * - an array of two or more objects with more than one property each, or of two or more arrays
 *   with more than one item each, always breaks, as Prettier breaks it;
 * - an array of numbers that breaks is filled, as many numbers per line as fit;
 * - a value of a property that is not an object or an array and does not fit moves to the next
 *   line, indented, unless the key is shorter than five characters, as Prettier keeps it after a
 *   short key.
 */
#[Internal]
final readonly class LiteralPrinter
{
    /** Prettier's printWidth in js/tooling/prettier.js. */
    public const int PRINT_WIDTH = 100;

    private const int TAB_WIDTH = 2;

    /**
     * How much longer than the indent a key must be before Prettier moves a string that does not
     * fit to the next line: a shorter key would gain too little.
     */
    private const int MIN_OVERLAP_FOR_BREAK = 3;

    /**
     * A constant of the module that holds the literal, `const <name>: <type> = <literal>;`, with
     * the literal on the next line, indented, when the line up to its first character does not fit.
     *
     * @return list<string>
     */
    public static function constant(string $name, string $type, Literal $literal): array
    {
        $head = 'const '.$name.': '.$type.' =';

        if (strlen($head) + 2 <= self::PRINT_WIDTH) {
            return explode("\n", $head.' '.self::print($literal, strlen($head) + 1, 0, 1).';');
        }

        return [$head, ...explode("\n", str_repeat(' ', self::TAB_WIDTH).self::print($literal, self::TAB_WIDTH, self::TAB_WIDTH, 1).';')];
    }

    /**
     * The literal printed from $column, the column its first character is at, with $indent spaces
     * before each line it breaks into, and $suffix characters after it before the next place
     * Prettier may break, such as `,` or `;`.
     */
    public static function print(Literal $literal, int $column, int $indent, int $suffix): string
    {
        $flat = $literal->flat();

        if (! self::breaks($literal) && $column + strlen($flat) + $suffix <= self::PRINT_WIDTH) {
            return $flat;
        }

        return match (true) {
            $literal instanceof ObjectLiteral => self::object($literal, $indent),
            $literal instanceof ArrayLiteral => self::array($literal, $indent),
            default => $flat,
        };
    }

    /**
     * Whether Prettier breaks the literal whatever its width.
     */
    private static function breaks(Literal $literal): bool
    {
        if (! $literal instanceof ArrayLiteral || count($literal->items) < 2) {
            return false;
        }

        $first = $literal->items[0];

        return array_all($literal->items, static fn (Literal $item): bool => $item::class === $first::class && match (true) {
            $item instanceof ObjectLiteral => count($item->properties) > 1,
            $item instanceof ArrayLiteral => count($item->items) > 1,
            default => false,
        });
    }

    private static function object(ObjectLiteral $object, int $indent): string
    {
        $inner = $indent + self::TAB_WIDTH;
        $lines = ['{'];

        foreach ($object->properties as $property) {
            $lines[] = self::property($property, $inner);
        }

        $lines[] = str_repeat(' ', $indent).'}';

        return implode("\n", $lines);
    }

    private static function property(Property $property, int $indent): string
    {
        $prefix = str_repeat(' ', $indent).$property->key().':';
        $value = $property->value;

        $scalar = ! $value instanceof ObjectLiteral && ! $value instanceof ArrayLiteral;

        if ($scalar && strlen($property->key()) >= self::TAB_WIDTH + self::MIN_OVERLAP_FOR_BREAK && strlen($prefix) + 1 + strlen($value->flat()) + 1 > self::PRINT_WIDTH) {
            return $prefix."\n".str_repeat(' ', $indent + self::TAB_WIDTH).$value->flat().',';
        }

        return $prefix.' '.self::print($value, strlen($prefix) + 1, $indent, 1).',';
    }

    private static function array(ArrayLiteral $array, int $indent): string
    {
        $inner = $indent + self::TAB_WIDTH;
        $pad = str_repeat(' ', $inner);
        $lines = ['['];

        if (array_all($array->items, static fn (Literal $item): bool => $item instanceof NumberLiteral)) {
            $line = '';

            foreach ($array->items as $item) {
                $next = $line === '' ? $pad.$item->flat().',' : $line.' '.$item->flat().',';

                if ($line !== '' && strlen($next) > self::PRINT_WIDTH) {
                    $lines[] = $line;
                    $next = $pad.$item->flat().',';
                }

                $line = $next;
            }

            $lines[] = $line;
        } else {
            foreach ($array->items as $item) {
                $lines[] = $pad.self::print($item, $inner, $inner, 1).',';
            }
        }

        $lines[] = str_repeat(' ', $indent).']';

        return implode("\n", $lines);
    }
}
