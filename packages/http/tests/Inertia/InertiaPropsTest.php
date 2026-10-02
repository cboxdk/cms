<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Inertia;

use Cbox\Cms\Http\Inertia\Boundary\InertiaProps;
use JsonException;
use UnexpectedValueException;

/*
 * The JSON a codec wrote, read back for Inertia's props: an object as deep as json_decode's depth
 * of 512 allows, and nothing else.
 */

it('reads a codec\'s document as deep as json_decode\'s depth of 512 allows and refuses a deeper one, and text that is not JSON with the parser\'s reason', function (): void {
    $nested = static fn (int $levels): string => str_repeat('{"a":', $levels - 1).'{}'.str_repeat('}', $levels - 1);

    expect(InertiaProps::document($nested(511)))->toBeArray()
        ->and(static fn (): array => InertiaProps::document($nested(512)))->toThrow(UnexpectedValueException::class, 'A codec wrote text that is not JSON: Maximum stack depth exceeded')
        ->and(static fn (): array => InertiaProps::document('{'))->toThrow(UnexpectedValueException::class, 'A codec wrote text that is not JSON: Syntax error')
        ->and(static fn (): array => InertiaProps::document('"text"'))->toThrow(UnexpectedValueException::class, 'A codec wrote a JSON document that is not an object.');
});

it('keeps the parser\'s exception as the cause, with the exception code 0', function (): void {
    try {
        InertiaProps::document('{');
    } catch (UnexpectedValueException $refused) {
        expect($refused->getCode())->toBe(0)
            ->and($refused->getPrevious())->toBeInstanceOf(JsonException::class);
    }
});
