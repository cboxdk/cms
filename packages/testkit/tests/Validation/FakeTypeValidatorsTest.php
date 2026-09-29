<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Validation;

use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Testkit\Tests\Schema\SampleTypes;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use InvalidArgumentException;

/*
 * The fake TypeValidators holds one validator per type, as the generated class does.
 */

it('refuses two validators for one type', function (): void {
    expect(static fn (): FakeTypeValidators => new FakeTypeValidators(SampleValidator::note(), SampleValidator::note()))
        ->toThrow(InvalidArgumentException::class, 'Two validators are for the type '.SampleTypes::NOTE_ID.'; a type has one.');
});

it('lists the validators sorted by type id', function (): void {
    $validators = new FakeTypeValidators(SampleValidator::appNote(), SampleValidator::note());

    expect(array_map(static fn (TypeValidator $validator): string => $validator->type()->toString(), $validators->all()))
        ->toBe([SampleTypes::NOTE_ID, SampleTypes::APP_NOTE_ID]);
});
