<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Boundary;

use Cbox\Cms\Cli\Boundary\RebuildArguments;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildRequest;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;
use InvalidArgumentException;

/*
 * The arguments of cms:types:rebuild: the type and the run's name, or the Clock's time.
 */

it('reads the type and the run, and names the run by the Clock\'s time in UTC when it is left out', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-09-30T23:04:05.123456+00:00'));

    $named = RebuildArguments::request('app:news', 'nightly-1', $clock);
    $timed = RebuildArguments::request('app:news', null, $clock);

    expect([$named->type->value, $named->run, $named->key->value])->toBe(['app:news', 'nightly-1', 'app:news@nightly-1'])
        ->and([$timed->run, $timed->key->value])->toBe(['20260930T230405Z', 'app:news@20260930T230405Z']);
});

it('refuses a type that is not a type name and a run name that is not 1 to 100 visible characters', function (mixed $type, mixed $run, string $message): void {
    expect(fn (): RebuildRequest => RebuildArguments::request($type, $run, new FakeClock))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no type' => [null, null, 'Name the type to rebuild as <owner>:<handle>.'],
    'a handle alone' => ['news', null, '"news" is not a type name'],
    'an empty run' => ['app:news', '', '--run names the run'],
    'a run with a space' => ['app:news', 'a b', '--run names the run'],
    'a run of 101 characters' => ['app:news', str_repeat('r', 101), '--run names the run'],
    'a run that is not text' => ['app:news', ['nightly'], '--run names the run'],
]);
