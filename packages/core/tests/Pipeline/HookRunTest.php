<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Pipeline\Domain\Dto\HookRun;

/*
 * The errors the validate hooks of a command add up, in the order they were given, as a list.
 */

it('adds the errors of each hook after those before it, as a list, also when they are given by name', function (): void {
    $first = new CatalogError(ErrorCode::ValidationHookFailed, null, 'first');
    $second = new CatalogError(ErrorCode::ValidationHookFailed, null, 'second');
    $third = new CatalogError(ErrorCode::ValidationHookFailed, null, 'third');

    $run = new HookRun(new Plan)->withErrors($first)->withErrors(...['b' => $second, 'c' => $third]);

    expect($run->errors)->toBe([$first, $second, $third]);
});
