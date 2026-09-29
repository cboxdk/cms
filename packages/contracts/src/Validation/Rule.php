<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One rule of a field's runtime validator (PRD 11.12): its name and its arguments, each written as
 * the blueprint writes the value, such as `255`, `2026-01-01` or an option's value.
 *
 * The number of arguments and their form follow from the name:
 *
 * - the type rules and `distinct` take none;
 * - `decimal` takes the precision, 1 to 38, and the scale, 0 to the precision;
 * - `min_length`, `max_length`, `min_items` and `max_items` take a whole number of 0 or more;
 * - `min` and `max` take one bound, which FieldRules reads as its type rule's kind;
 * - `format` takes `email` or `url`;
 * - `in` and `items_in` take one or more options, `styles`, `marks`, `lists` and `links` any
 *   number of allowed values, each non-empty and each once.
 */
#[Experimental]
final readonly class Rule
{
    private const int MAX_PRECISION = 38;

    /**
     * @param  list<string>  $arguments
     *
     * @throws InvalidRules
     */
    public function __construct(
        public RuleName $name,
        public array $arguments = [],
    ) {
        $first = $arguments[0] ?? '';

        if ($name === RuleName::Decimal) {
            $this->decimalArguments();
        } elseif (in_array($name, [RuleName::MinLength, RuleName::MaxLength, RuleName::MinItems, RuleName::MaxItems], true)) {
            $this->count(1);
            $this->wholeNumber($first);
        } elseif ($name === RuleName::Min || $name === RuleName::Max) {
            $this->count(1);
        } elseif ($name === RuleName::Format) {
            $this->count(1);
            $this->oneOf($first, RuleName::FORMATS);
        } elseif (in_array($name, [RuleName::In, RuleName::ItemsIn, RuleName::Styles, RuleName::Marks, RuleName::Lists, RuleName::Links], true)) {
            $this->values($name === RuleName::In || $name === RuleName::ItemsIn);
        } else {
            $this->count(0);
        }
    }

    /**
     * The argument as a whole number; only for a rule whose argument is one, as the constructor
     * checked.
     */
    public function number(): int
    {
        return (int) ($this->arguments[0] ?? '0');
    }

    private function count(int $count): void
    {
        if (count($this->arguments) !== $count) {
            throw InvalidRules::argumentCount($this->name, $count, count($this->arguments));
        }
    }

    private function decimalArguments(): void
    {
        $this->count(2);
        $precision = RuleValues::integer($this->arguments[0] ?? '');
        $scale = RuleValues::integer($this->arguments[1] ?? '');

        if ($precision === null || $scale === null || $precision < 1 || $precision > self::MAX_PRECISION || $scale < 0 || $scale > $precision) {
            throw InvalidRules::argument($this->name, sprintf('a precision of 1 to %d and a scale of 0 to the precision', self::MAX_PRECISION), implode(', ', $this->arguments));
        }
    }

    private function wholeNumber(string $argument): void
    {
        $number = RuleValues::integer($argument);

        if ($number === null || $number < 0) {
            throw InvalidRules::argument($this->name, 'a whole number of 0 or more', $argument);
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function oneOf(string $argument, array $allowed): void
    {
        if (! in_array($argument, $allowed, true)) {
            throw InvalidRules::argument($this->name, 'one of '.implode(', ', $allowed), $argument);
        }
    }

    private function values(bool $atLeastOne): void
    {
        if ($atLeastOne && $this->arguments === []) {
            throw InvalidRules::argumentCount($this->name, 1, 0);
        }

        foreach ($this->arguments as $index => $argument) {
            if ($argument === '' || array_search($argument, $this->arguments, true) !== $index) {
                throw InvalidRules::argument($this->name, 'values that are each non-empty and given once', $argument);
            }
        }
    }
}
