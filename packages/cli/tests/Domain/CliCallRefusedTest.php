<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Domain;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use LogicException;

/*
 * A refusal of the CLI surface carries its exit code in its own property; the exception's code
 * stays 0, so nothing mistakes it for the process's exit code. Each refusal is made inside the
 * test, so the coverage that picks the tests of a mutation counts it for this test.
 */

it('carries the exit code of its kind and keeps the exception code 0', function (string $kind, ExitCode $exit): void {
    $refused = match ($kind) {
        'catalog' => CliCallRefused::catalog(ErrorCode::JsonInvalid, null, 'refused'),
        'usage' => CliCallRefused::usage('refused', new LogicException('cause')),
        'config' => CliCallRefused::config('refused'),
        default => CliCallRefused::software('refused'),
    };

    expect($refused->exit)->toBe($exit)
        ->and($refused->getCode())->toBe(0)
        ->and($refused->getMessage())->toBe('refused');
})->with([
    'catalog' => ['catalog', ExitCode::DataErr],
    'usage' => ['usage', ExitCode::Usage],
    'config' => ['config', ExitCode::Config],
    'software' => ['software', ExitCode::Software],
]);
