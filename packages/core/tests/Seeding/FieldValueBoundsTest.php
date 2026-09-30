<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Pipeline\Boundary\TypeRulesFieldValidation;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\FieldValueGenerator;
use Cbox\Cms\Core\Seeding\Domain\SeedProfiles;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use LogicException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
 * The seeder's values stay inside every bound and format of a field's rules (GUARDRAILS 4.3): a
 * decimal bound with more digits than the scale is rounded into the range, a number bounded on one
 * side reaches a thousand past it, lengths, formats, choices and list sizes hold, rich text uses
 * only the styles the field allows, and dates and date-times outside the bounds are clamped.
 */

/**
 * @return list<FieldMap>
 */
function boundedSamples(int $count): array
{
    $type = new SeedableType(RulesType::definition(), new RulesType()->rules(), false);
    $generator = new FieldValueGenerator(SeedProfiles::small());
    $validation = new TypeRulesFieldValidation(new FakeTypeValidators(new RulesType), app(InputValidator::class));
    $samples = [];

    for ($seed = 0; $seed < $count; $seed++) {
        $fields = $generator->fields($type, ClassificationAccess::Public, new Randomizer(new Xoshiro256StarStar(hash('sha256', 'bounds:'.$seed, true))));

        expect($validation->validate($type->definition, $fields, ValidationStage::Release, new FieldPath('fields'))->errors)->toBe([], 'seed '.$seed);

        $samples[] = $fields->own;
    }

    return $samples;
}

function boundedValue(FieldMap $fields, string $handle): FieldValue
{
    return $fields->get(new FieldHandle($handle)) ?? throw new LogicException(sprintf('The sample has no %s.', $handle));
}

it('writes every required field within its bounds and formats', function (): void {
    $amounts = [];
    $debts = [];
    $counts = [];
    $floors = [];

    foreach (boundedSamples(300) as $fields) {
        expect(array_map(static fn (FieldHandle $handle): string => $handle->value, $fields->handles()))->toBe(RulesType::HANDLES);

        $amount = boundedValue($fields, 'amount');
        $debt = boundedValue($fields, 'debt');
        $count = boundedValue($fields, 'count');
        $floor = boundedValue($fields, 'floor');
        $code = boundedValue($fields, 'code');
        $contact = boundedValue($fields, 'contact');
        $link = boundedValue($fields, 'link');
        $mood = boundedValue($fields, 'mood');
        $picks = boundedValue($fields, 'picks');
        $rows = boundedValue($fields, 'rows');

        expect($amount)->toBeInstanceOf(DecimalValue::class)
            ->and($debt)->toBeInstanceOf(DecimalValue::class)
            ->and($count)->toBeInstanceOf(IntegerValue::class)
            ->and($floor)->toBeInstanceOf(IntegerValue::class)
            ->and($code instanceof TextValue ? strlen($code->value) : 0)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(8)
            ->and($contact instanceof TextValue ? $contact->value : '')->toMatch('/\A[a-z]+\.[a-z]+@example\.org\z/')
            ->and($link instanceof TextValue ? $link->value : '')->toMatch('#\Ahttps://example\.org/[a-z]+/[a-z]+\z#')
            ->and($mood instanceof TextValue ? $mood->value : '')->toBeIn(['calm', 'bright', 'grey'])
            ->and($picks instanceof ListValue ? count($picks->items) : 0)->toBe(2)
            ->and($rows instanceof ListValue ? count($rows->items) : 0)->toBe(2)
            ->and($rows instanceof ListValue ? $rows->items[0] : null)->toBeInstanceOf(GroupValue::class);

        $amounts[] = $amount instanceof DecimalValue ? (float) $amount->value : 0.0;
        $debts[] = $debt instanceof DecimalValue ? (float) $debt->value : 0.0;
        $counts[] = $count instanceof IntegerValue ? $count->value : 0;
        $floors[] = $floor instanceof IntegerValue ? $floor->value : 0;
    }

    expect(min([INF, ...$amounts]))->toBe(0.01)
        ->and(max([-INF, ...$amounts]))->toBeLessThanOrEqual(9.99)
        ->and(min([INF, ...$debts]))->toBeGreaterThanOrEqual(-50.5)
        ->and(max([-INF, ...$debts]))->toBeLessThanOrEqual(-0.1)
        ->and(min([PHP_INT_MAX, ...$counts]))->toBeGreaterThanOrEqual(-1005)
        ->and(max([PHP_INT_MIN, ...$counts]))->toBeLessThanOrEqual(-5)
        ->and(min([PHP_INT_MAX, ...$floors]))->toBe(10)
        ->and(max([PHP_INT_MIN, ...$floors]))->toBeLessThanOrEqual(1010)
        ->and(count(array_filter($floors, static fn (int $floor): bool => $floor < 260)))->toBeGreaterThan(150);
});

it('uses only the rich text styles a field allows and clamps dates to their bounds', function (): void {
    $atMax = 0;

    foreach (boundedSamples(60) as $fields) {
        $body = boundedValue($fields, 'body');
        $plain = boundedValue($fields, 'plain');
        $day = boundedValue($fields, 'day');
        $stamp = boundedValue($fields, 'stamp');

        foreach ($body instanceof ListValue ? $body->items : [] as $block) {
            expect($block instanceof MapValue ? $block->get('style') : null)->toEqual(new TextValue('h2'));
        }

        foreach ($plain instanceof ListValue ? $plain->items : [] as $block) {
            expect($block instanceof MapValue ? $block->get('style') : 'no block')->toBeNull();
        }

        $atMax += $day instanceof DateValue && $day->value === '2020-01-01' ? 1 : 0;

        expect($day instanceof DateValue ? $day->value : '')->toBeLessThanOrEqual('2020-01-01')
            ->and($stamp instanceof DateTimeValue ? $stamp->value->format('Y-m-d\TH:i:s') : '')->toBe('2026-06-01T00:00:00');
    }

    expect($atMax)->toBeGreaterThan(40);
});
