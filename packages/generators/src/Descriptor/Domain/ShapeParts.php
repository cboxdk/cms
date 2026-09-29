<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;

/**
 * The CHECK expressions and validation rules that several field types describe alike: bounds on a
 * value and on its length.
 */
#[Internal]
final readonly class ShapeParts
{
    /**
     * `<column> >= <min>` and `<column> <= <max>` for the bounds that are set, each given as SQL.
     *
     * @return list<string>
     */
    public static function boundChecks(string $column, ?string $min, ?string $max): array
    {
        $checks = [];

        if ($min !== null) {
            $checks[] = $column.' >= '.$min;
        }

        if ($max !== null) {
            $checks[] = $column.' <= '.$max;
        }

        return $checks;
    }

    /**
     * `min` and `max` for the bounds that are set, as the blueprint file writes them.
     *
     * @return list<ValidationRule>
     */
    public static function boundRules(?string $min, ?string $max): array
    {
        $rules = [];

        if ($min !== null) {
            $rules[] = new ValidationRule(ValidationRuleName::Min, [$min]);
        }

        if ($max !== null) {
            $rules[] = new ValidationRule(ValidationRuleName::Max, [$max]);
        }

        return $rules;
    }

    /**
     * The length of a text in characters, at least $min when it is set and at most $max.
     *
     * @return list<string>
     */
    public static function lengthChecks(string $column, ?int $min, int $max): array
    {
        return self::boundChecks('char_length('.$column.')', $min === null ? null : (string) $min, (string) $max);
    }

    /**
     * @return list<ValidationRule>
     */
    public static function lengthRules(?int $min, int $max): array
    {
        return [
            ...($min === null ? [] : [new ValidationRule(ValidationRuleName::MinLength, [(string) $min])]),
            new ValidationRule(ValidationRuleName::MaxLength, [(string) $max]),
        ];
    }

    /**
     * `min_items` when it is set and `max_items` when it is set.
     *
     * @return list<ValidationRule>
     */
    public static function itemRules(?int $min, ?int $max): array
    {
        return [
            ...($min === null ? [] : [new ValidationRule(ValidationRuleName::MinItems, [(string) $min])]),
            ...($max === null ? [] : [new ValidationRule(ValidationRuleName::MaxItems, [(string) $max])]),
        ];
    }
}
