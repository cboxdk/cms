<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;

/**
 * The rules that several field types share for their options, as FieldOptions::problems() reports
 * them: a lower bound at most its upper bound, each with its own code at the lower bound's pointer.
 */
#[Internal]
final readonly class OptionRules
{
    /**
     * `min_length` at most `max_length`, which is the type's default when the field leaves it out.
     *
     * @return list<GenerationProblem>
     */
    public static function lengths(?int $minLength, int $maxLength, int $defaultMaxLength, SourceLocation $field): array
    {
        if ($minLength === null || $minLength <= $maxLength) {
            return [];
        }

        return [self::problem(GenerateErrorCode::MinLengthAboveMaxLength, $field->below('min_length'), sprintf(
            'min_length %d is greater than max_length %d, which is %d when the field leaves it out. Make min_length at most max_length.',
            $minLength,
            $maxLength,
            $defaultMaxLength,
        ))];
    }

    /**
     * `min` at most `max`.
     *
     * @param  int  $comparison  min compared with max
     * @return list<GenerationProblem>
     */
    public static function range(int $comparison, string $min, string $max, SourceLocation $field): array
    {
        if ($comparison <= 0) {
            return [];
        }

        return [self::problem(GenerateErrorCode::MinAboveMax, $field->below('min'), sprintf(
            'min %s is greater than max %s, so no value fits. Make min at most max.',
            $min,
            $max,
        ))];
    }

    /**
     * `min_items` at most `max_items`.
     *
     * @param  SourceLocation  $at  the object that holds both
     * @return list<GenerationProblem>
     */
    public static function items(?int $minItems, ?int $maxItems, SourceLocation $at): array
    {
        if ($minItems === null || $maxItems === null || $minItems <= $maxItems) {
            return [];
        }

        return [self::problem(GenerateErrorCode::MinItemsAboveMaxItems, $at->below('min_items'), sprintf(
            'min_items %d is greater than max_items %d, so no list of items fits. Make min_items at most max_items.',
            $minItems,
            $maxItems,
        ))];
    }

    /**
     * A problem named by the place that breaks the rule: `schema/article.yaml, /fields/0/min: ...`.
     */
    public static function problem(GenerateErrorCode $code, SourceLocation $at, string $what): GenerationProblem
    {
        return new GenerationProblem($code, sprintf('%s: %s', $at->describe(), $what));
    }
}
