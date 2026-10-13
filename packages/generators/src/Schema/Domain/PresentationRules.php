<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\BooleanOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldDefault;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldUi;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\ShapedOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;

/**
 * The rules of a field's presentation keys (gap G2) that JSON Schema cannot express, because
 * they compare the key with the field's type or its nested fields:
 *
 * - a `default` is of the kind the field type takes (generate_default_wrong_kind): a string for
 *   `text` and `long_text`, an integer for `integer`, a decimal string for `decimal`, a boolean
 *   for `boolean`, a date or the token `today` for `date`, a time or the token `now` for
 *   `datetime`, one of the options for `select`, or a list of them, each once, with `multiple`;
 *   `rich_text` and `group` take none, and an addon's field type takes what its base takes;
 * - the `ui.summary` of a repeated group names, in braces, only fields the group has
 *   (generate_summary_field_unknown).
 */
#[Internal]
final readonly class PresentationRules
{
    /**
     * @return list<GenerationProblem>
     */
    public static function problems(FieldBlueprint $field): array
    {
        $problems = [];
        $options = $field->options instanceof ShapedOptions ? $field->options->base : $field->options;

        if ($field->default instanceof FieldDefault) {
            $takes = self::takes($options, $field->default);

            if ($takes !== null) {
                $problems[] = new GenerationProblem(GenerateErrorCode::DefaultWrongKind, sprintf(
                    '%s: the default is %s, and a %s field takes %s. Correct the default, or remove it.',
                    $field->location->below('default')->describe(),
                    $field->default->describe(),
                    $field->options->typeName(),
                    $takes,
                ));
            }
        }

        if ($field->ui instanceof FieldUi && $field->ui->summary !== null) {
            $nested = array_map(static fn (FieldBlueprint $inner): string => $inner->handle->value, $field->options->nestedFields());

            foreach ($field->ui->summaryHandles() as $handle) {
                if (! in_array($handle, $nested, true)) {
                    $problems[] = new GenerationProblem(GenerateErrorCode::SummaryFieldUnknown, sprintf(
                        '%s: the summary names {%s}, and the group %s has no field %s. Name one of its fields: %s.',
                        $field->location->below('ui', 'summary')->describe(),
                        $handle,
                        $field->handle->value,
                        $handle,
                        $nested === [] ? 'it has none' : implode(', ', $nested),
                    ));
                }
            }
        }

        return $problems;
    }

    /**
     * What the field type takes, when the default is not of that kind; null when it is.
     */
    private static function takes(FieldOptions $options, FieldDefault $default): ?string
    {
        $constant = $default->constant;

        return match (true) {
            $options instanceof TextOptions, $options instanceof LongTextOptions => is_string($constant) ? null : 'a string',
            $options instanceof IntegerOptions => is_int($constant) ? null : 'an integer',
            $options instanceof DecimalOptions => is_string($constant) && self::accepts(static fn (): DecimalBound => new DecimalBound($constant)) ? null : 'a decimal as a quoted string, such as "0.00"',
            $options instanceof BooleanOptions => is_bool($constant) ? null : 'true or false',
            $options instanceof DateOptions => $default->token === DefaultToken::Today || (is_string($constant) && self::accepts(static fn (): BlueprintDate => new BlueprintDate($constant))) ? null : 'a quoted date, such as "2026-01-01", or the token today',
            $options instanceof DatetimeOptions => $default->token === DefaultToken::Now || (is_string($constant) && self::accepts(static fn (): BlueprintDatetime => new BlueprintDatetime($constant))) ? null : 'a quoted time with its offset, such as "2026-01-01T00:00:00Z", or the token now',
            $options instanceof SelectOptions => self::selectTakes($options, $default),
            default => 'no default',
        };
    }

    private static function selectTakes(SelectOptions $options, FieldDefault $default): ?string
    {
        $values = array_map(static fn (SelectOption $option): string => $option->value->value, $options->options);
        $named = implode(', ', $values);

        if ($options->multiple) {
            $given = $default->values;

            return is_array($given) && $given === array_values(array_unique($given)) && array_diff($given, $values) === [] ? null : 'a list of its options, each once: '.$named;
        }

        return is_string($default->constant) && in_array($default->constant, $values, true) ? null : 'one of its options: '.$named;
    }

    /**
     * @param  callable(): object  $make  throws GenerationFailed for a string of another form
     */
    private static function accepts(callable $make): bool
    {
        try {
            $make();

            return true;
        } catch (GenerationFailed) {
            return false;
        }
    }
}
