<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Pipeline;

use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\InvalidEnvelope;
use Cbox\Cms\Contracts\Envelope\ReasonCode;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\InvalidFieldValue;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\InvalidWriteResult;
use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/*
 * How the pipeline's value objects show rejected input in their messages, as the idempotency
 * values do: at most 64 bytes, then "...", with control characters, bytes above ASCII, the quote
 * and the backslash escaped, so a message is one readable line whatever the input was.
 */

/**
 * The message a constructor throws for the value.
 *
 * @param  Closure(string): mixed  $make
 */
function pipelineRejection(Closure $make, string $value): string
{
    try {
        $make($value);
    } catch (InvalidArgumentException $rejected) {
        return $rejected->getMessage();
    }

    throw new UnexpectedValueException("The value was accepted: {$value}");
}

dataset('rejecting pipeline constructors', [
    'a locale' => [
        static fn (string $value): Locale => new Locale($value),
        InvalidContentValue::class,
        'A locale is a BCP 47 tag of a language, an optional script and an optional region, such as "da", "en-GB" or "sr-Latn", got "%s".',
    ],
    'a field handle' => [
        static fn (string $value): FieldHandle => new FieldHandle($value),
        InvalidFieldValue::class,
        'A field handle is lowercase snake_case of at most 63 bytes without a double underscore, and neither "ext" nor starting with "cms_", got "%s".',
    ],
    'a field namespace' => [
        static fn (string $value): FieldNamespace => new FieldNamespace($value),
        InvalidFieldValue::class,
        'A field namespace is a lowercase letter followed by at most 19 lowercase letters and digits, and not "ext", got "%s".',
    ],
    'a decimal' => [
        static fn (string $value): DecimalValue => new DecimalValue($value),
        InvalidFieldValue::class,
        'A decimal value is an optional minus sign, digits, and optionally a point and digits, such as "-12.50", got "%s".',
    ],
    'a date' => [
        static fn (string $value): DateValue => new DateValue($value),
        InvalidFieldValue::class,
        'A date value is a real date as YYYY-MM-DD from year 0001, such as "2026-09-29", got "%s".',
    ],
    'a correlation id' => [
        static fn (string $value): CorrelationId => new CorrelationId($value),
        InvalidEnvelope::class,
        'A correlation id is 1 to 128 visible ASCII characters, got "%s".',
    ],
    'a unit of work' => [
        static fn (string $value): UnitOfWork => new UnitOfWork($value),
        InvalidEnvelope::class,
        'A unit of work is 1 to 255 visible ASCII characters, such as "event:<event id>:<step>", got "%s".',
    ],
    'a reason code' => [
        static fn (string $value): ReasonCode => new ReasonCode($value),
        InvalidEnvelope::class,
        'A reason code is lowercase snake_case of at most 63 bytes, got "%s".',
    ],
    'a field path name' => [
        static fn (string $value): FieldPath => new FieldPath($value),
        InvalidWriteResult::class,
        'A field path name is a letter or an underscore followed by letters, digits and underscores, got "%s".',
    ],
]);

it('shows rejected input of up to 64 bytes whole, escaped', function (Closure $make, string $class, string $message): void {
    // 61 characters, a backslash, a quote and a line break: 64 bytes, none cut.
    $value = str_repeat('a b', 20).'x\\"'."\n";

    expect(strlen($value))->toBe(64)
        ->and(static fn (): mixed => $make($value))->toThrow($class)
        ->and(pipelineRejection($make, $value))->toBe(sprintf($message, str_repeat('a b', 20).'x\\\\\"\n'));
})->with('rejecting pipeline constructors');

it('cuts rejected input after 64 bytes and marks the cut', function (Closure $make, string $class, string $message): void {
    $value = str_repeat('"', 64).'tail that is not shown';

    expect(pipelineRejection($make, $value))->toBe(sprintf($message, str_repeat('\"', 64).'...'))
        ->and(pipelineRejection($make, str_repeat(' ', 65)))->toBe(sprintf($message, str_repeat(' ', 64).'...'));
})->with('rejecting pipeline constructors');

it('escapes bytes that are not visible ASCII as octal', function (Closure $make, string $class, string $message): void {
    expect(pipelineRejection($make, "\0é\x7F"))->toBe(sprintf($message, '\000\303\251\177'));
})->with('rejecting pipeline constructors');

it('names the empty input as empty quotes', function (Closure $make, string $class, string $message): void {
    expect(pipelineRejection($make, ''))->toBe(sprintf($message, ''));
})->with('rejecting pipeline constructors');
