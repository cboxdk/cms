<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Closure;
use InvalidArgumentException;
use LogicException;

/*
 * The presence of a field whose key is always written (GUARDRAILS 2.2): present() needs the key
 * and takes null, defaulted() gives a missing key its default and refuses null, defaultedNullable()
 * takes both; value() makes a value object of one string, and build() turns the refusal of a
 * contract's constructor into DecodingFailed.
 */

/**
 * @return Closure(mixed, FieldPath): string
 */
function presenceText(): Closure
{
    return static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at);
}

it('needs a present key, and takes null for it', function (): void {
    $path = new FieldPath('position');

    expect(JsonValues::present(['a' => 'x'], 'a', null, presenceText()))->toBe('x')
        ->and(JsonValues::present(['a' => null], 'a', null, presenceText()))->toBeNull()
        ->and(JsonValues::present(['a' => 'x'], 'a', $path, static fn (mixed $value, FieldPath $at): string => $at->toString()))->toBe('position.a')
        ->and(Failures::described(static fn (): ?string => JsonValues::present([], 'a', $path, presenceText())))->toBe(['json_invalid', 'position.a', 'is missing, and the field is required'])
        ->and(Failures::described(static fn (): ?string => JsonValues::present(['a' => 1], 'a', null, presenceText())))->toBe(['json_invalid', 'a', 'is not a string']);
});

it('gives a missing key its default, and refuses null unless the field is nullable', function (): void {
    $path = new FieldPath('provenance');

    expect(JsonValues::defaulted([], 'a', null, presenceText(), 'fallback'))->toBe('fallback')
        ->and(JsonValues::defaulted(['a' => 'x'], 'a', null, presenceText(), 'fallback'))->toBe('x')
        ->and(JsonValues::defaulted(['a' => 'x'], 'a', $path, static fn (mixed $value, FieldPath $at): string => $at->toString(), 'fallback'))->toBe('provenance.a')
        ->and(Failures::described(static fn (): string => JsonValues::defaulted(['a' => null], 'a', $path, presenceText(), 'fallback')))->toBe(['json_invalid', 'provenance.a', 'is null, and the field is not nullable'])
        ->and(Failures::described(static fn (): string => JsonValues::defaulted(['a' => false], 'a', null, presenceText(), 'fallback')))->toBe(['json_invalid', 'a', 'is not a string'])
        ->and(JsonValues::defaultedNullable([], 'a', null, presenceText(), 'fallback'))->toBe('fallback')
        ->and(JsonValues::defaultedNullable([], 'a', null, presenceText(), null))->toBeNull()
        ->and(JsonValues::defaultedNullable(['a' => null], 'a', null, presenceText(), 'fallback'))->toBeNull()
        ->and(JsonValues::defaultedNullable(['a' => 'x'], 'a', $path, static fn (mixed $value, FieldPath $at): string => $at->toString(), null))->toBe('provenance.a')
        ->and(Failures::described(static fn (): ?string => JsonValues::defaultedNullable(['a' => 2], 'a', null, presenceText(), null)))->toBe(['json_invalid', 'a', 'is not a string']);
});

it('makes a value object from a string, and turns its refusal into json_invalid at the value', function (): void {
    $make = static fn (string $text): ProjectionName => new ProjectionName($text);
    $at = new FieldPath('projections', 0, 'projection');
    $refused = Failures::of(static fn (): ProjectionName => JsonValues::value('Search', $at, $make));

    expect(JsonValues::value('search', $at, $make))->toEqual(new ProjectionName('search'))
        ->and(Failures::described(static fn (): ProjectionName => JsonValues::value(7, $at, $make)))->toBe(['json_invalid', 'projections[0].projection', 'is not a string'])
        ->and([$refused->errorCode->value, $refused->path?->toString()])->toBe(['json_invalid', 'projections[0].projection'])
        ->and($refused->reason)->toStartWith('is not valid: A projection name is dot-separated snake_case segments')
        ->and($refused->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
});

it('builds an object and turns a contract\'s refusal into json_invalid at the object, but lets other errors through', function (): void {
    $path = new FieldPath('projections', 1);
    $refused = Failures::of(static fn (): object => JsonValues::build($path, static fn (): ProjectionName => new ProjectionName('Bad')));
    $document = Failures::of(static fn (): object => JsonValues::build(null, static fn (): ProjectionName => new ProjectionName('Bad')));
    $inner = DecodingFailed::invalid(new FieldPath('inner'), 'is not a string');

    expect(JsonValues::build($path, static fn (): ProjectionName => new ProjectionName('search')))->toEqual(new ProjectionName('search'))
        ->and([$refused->errorCode->value, $refused->path?->toString()])->toBe(['json_invalid', 'projections[1]'])
        ->and($refused->reason)->toStartWith('breaks a rule of the contract: A projection name is')
        ->and($refused->getPrevious())->toBeInstanceOf(InvalidArgumentException::class)
        ->and($document->path)->toBeNull()
        ->and(Failures::of(static fn (): object => JsonValues::build($path, static fn (): object => throw $inner)))->toBe($inner)
        ->and(static fn (): object => JsonValues::build($path, static fn (): object => throw new LogicException('a bug')))->toThrow(LogicException::class, 'a bug');
});
