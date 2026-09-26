<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Phpstan;

/*
 * PRD 3.3: cms:doctor has fixed exit codes. They are defined once, in the enum DoctorExitCode;
 * the checks, the doctor and the command use its cases and never the numbers.
 */

/**
 * The PHP files of the doctor, relative to the monorepo root.
 *
 * @return list<string>
 */
function doctorSources(): array
{
    $root = Phpstan::root();
    $files = [
        ...glob($root.'/packages/contracts/src/Doctor/*.php') ?: [],
        ...glob($root.'/packages/core/src/Doctor/*/*.php') ?: [],
        ...glob($root.'/packages/core/src/Doctor/*/*/*.php') ?: [],
        $root.'/packages/cli/src/Console/DoctorCommand.php',
    ];

    return array_map(static fn (string $file): string => substr($file, strlen($root) + 1), $files);
}

/**
 * The integer literals in a PHP file.
 *
 * @return list<int>
 */
function integerLiterals(string $file): array
{
    $literals = [];

    foreach (token_get_all((string) file_get_contents(Phpstan::root().'/'.$file)) as $token) {
        if (is_array($token) && $token[0] === T_LNUMBER) {
            $literals[] = (int) str_replace('_', '', $token[1]);
        }
    }

    return $literals;
}

it('writes the numbers 75 and 78 only in DoctorExitCode', function (): void {
    $files = doctorSources();
    $withCodes = array_values(array_filter($files, static fn (string $file): bool => array_intersect(integerLiterals($file), [75, 78]) !== []));

    expect(count($files))->toBeGreaterThan(30)
        ->and($withCodes)->toBe(['packages/contracts/src/Doctor/DoctorExitCode.php']);
});

it('defines one exit code enum in the packages', function (): void {
    $enums = [];

    foreach (glob(Phpstan::root().'/packages/*/src/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE) ?: [] as $file) {
        if (preg_match_all('/^enum\s+(\w*Exit\w*)/m', (string) file_get_contents($file), $matches) > 0) {
            array_push($enums, ...$matches[1]);
        }
    }

    expect($enums)->toBe(['DoctorExitCode']);
});
