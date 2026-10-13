<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\TypeEditors\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Validation\Condition;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ArrayLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\BooleanLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\NumberLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ObjectLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\StringLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScriptEmitter;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use JsonException;
use stdClass;

/**
 * The value rule of a field as JSON (PRD 11.12, GUARDRAILS 2.2): the ValueRule of the generated
 * validation runtime that TypeScriptEmitter::rule() gives for the field's codec value, as the
 * record's TypeScript module declares it, written as one JSON object with its keys as the
 * runtime names them. The editor descriptor carries it per field, so a form validates with the
 * same interpreter as the record's validator.
 *
 * An integer bound the runtime writes as Infinity, because no integer a JavaScript number holds
 * exactly meets it, is written as the first integer past that range, which no such integer meets
 * either; JSON has no Infinity.
 */
#[Internal]
final readonly class RuleJson
{
    /** The first integer past Number.MAX_SAFE_INTEGER, which no safe integer reaches. */
    private const string PAST_SAFE_RANGE = '9007199254740992';

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput for a value that holds an object
     */
    public static function of(CodecValue $value): string
    {
        try {
            return json_encode(self::json(TypeScriptEmitter::rule($value)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, 'The rule of a field cannot be written as JSON: '.$exception->getMessage());
        }
    }

    /**
     * The condition of a conditional field as JSON (gap G3): the ConditionRule of the generated
     * validation runtime that TypeScriptEmitter::conditionRule() gives, as the record's module
     * declares it, so a form shows and hides the field with the runtime's conditionHolds().
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    public static function condition(Condition $condition): string
    {
        try {
            return json_encode(self::json(TypeScriptEmitter::conditionRule($condition)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, 'The condition of a field cannot be written as JSON: '.$exception->getMessage());
        }
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function json(Literal $literal): mixed
    {
        if ($literal instanceof ObjectLiteral) {
            $object = new stdClass;

            foreach ($literal->properties as $property) {
                $object->{$property->name} = self::json($property->value);
            }

            return $object;
        }

        if ($literal instanceof ArrayLiteral) {
            return array_map(self::json(...), $literal->items);
        }

        if ($literal instanceof StringLiteral) {
            return $literal->value;
        }

        if ($literal instanceof BooleanLiteral) {
            return $literal->value;
        }

        if ($literal instanceof NumberLiteral) {
            return self::number($literal->literal);
        }

        throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('The rule of a field holds a %s, which has no form in JSON.', $literal::class));
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function number(string $literal): int
    {
        $digits = match ($literal) {
            'Infinity' => self::PAST_SAFE_RANGE,
            '-Infinity' => '-'.self::PAST_SAFE_RANGE,
            default => $literal,
        };

        if (preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $digits) !== 1) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('The rule of a field holds the number %s, which is not an integer.', $literal));
        }

        return (int) $digits;
    }
}
