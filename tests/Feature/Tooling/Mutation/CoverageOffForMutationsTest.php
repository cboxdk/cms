<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Tooling\Mutation\Adapter\CoverageOffForMutations;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;
use Symfony\Component\Process\Process;

/*
 * The processes that test one mutation each collect no coverage, and with PCOV enabled each took
 * about half again as long. CoverageOffForMutations removes the PHP_INI_SCAN_DIR that
 * MutationSteps set before pest-plugin-mutate starts them, so they inherit the image's ini files.
 */

/**
 * The scan directory of the process before each test, to give back after it: the environment's,
 * $_ENV's and $_SERVER's.
 *
 * @return array{string|false, mixed, mixed}
 */
function scanDirectorySnapshot(bool $take = false): array
{
    /** @var array{string|false, mixed, mixed} $snapshot */
    static $snapshot = [false, null, null];

    if ($take) {
        $snapshot = [getenv(CoverageOffForMutations::VARIABLE), $_ENV[CoverageOffForMutations::VARIABLE] ?? null, $_SERVER[CoverageOffForMutations::VARIABLE] ?? null];
    }

    return $snapshot;
}

beforeEach(function (): void {
    scanDirectorySnapshot(true);
});

afterEach(function (): void {
    [$environment, $env, $server] = scanDirectorySnapshot();
    putenv($environment === false ? CoverageOffForMutations::VARIABLE : CoverageOffForMutations::VARIABLE.'='.$environment);
    unset($_ENV[CoverageOffForMutations::VARIABLE], $_SERVER[CoverageOffForMutations::VARIABLE]);

    if ($env !== null) {
        $_ENV[CoverageOffForMutations::VARIABLE] = $env;
    }

    if ($server !== null) {
        $_SERVER[CoverageOffForMutations::VARIABLE] = $server;
    }
});

it('removes the scan directory MutationSteps set from what the next processes inherit', function (): void {
    $value = MutationSteps::ENVIRONMENT[CoverageOffForMutations::VARIABLE];
    putenv(CoverageOffForMutations::VARIABLE.'='.$value);
    $_SERVER[CoverageOffForMutations::VARIABLE] = $value;

    expect(CoverageOffForMutations::turnOff())->toBeTrue()
        ->and(getenv(CoverageOffForMutations::VARIABLE))->toBeFalse()
        ->and($_SERVER)->not->toHaveKey(CoverageOffForMutations::VARIABLE);

    $child = new Process([PHP_BINARY, '-r', 'echo var_export(getenv("'.CoverageOffForMutations::VARIABLE.'"), true);']);
    $child->mustRun();

    expect($child->getOutput())->toBe('false');
});

it('leaves a scan directory it did not set alone', function (): void {
    putenv(CoverageOffForMutations::VARIABLE.'=/etc/php/custom');

    expect(CoverageOffForMutations::turnOff())->toBeFalse()
        ->and(getenv(CoverageOffForMutations::VARIABLE))->toBe('/etc/php/custom');
});
