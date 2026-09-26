<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Boundary;

use Cbox\Cms\Cli\Boundary\DoctorOptions;

it('runs the development checks only when --dev is given', function (mixed $dev, bool $expected): void {
    expect(DoctorOptions::parse($dev)->dev)->toBe($expected);
})->with([
    'the flag' => [true, true],
    'no flag' => [false, false],
    'nothing' => [null, false],
    'a string' => ['1', false],
]);
