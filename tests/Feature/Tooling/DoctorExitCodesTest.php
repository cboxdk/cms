<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Phpstan;

/*
 * PRD 3.3: cms:doctor has fixed exit codes. The checks, the doctor and the command use the cases
 * of the enum DoctorExitCode and never the numbers, and DoctorExitCode takes its values from the
 * error catalog's ExitCode (PRD 6.1), the one enum that writes the exit codes as numbers.
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

/**
 * The files that write 75, 78 or 79 as an integer literal.
 *
 * @param  list<string>  $files
 * @return list<string>
 */
function filesWritingExitCodes(array $files): array
{
    return array_values(array_filter($files, static fn (string $file): bool => array_intersect(integerLiterals($file), [75, 78, 79]) !== []));
}

it('writes the numbers 75, 78 and 79 in no source of the doctor, and in the contracts only in the catalog\'s ExitCode', function (): void {
    $files = doctorSources();
    $contracts = array_map(
        static fn (string $file): string => substr($file, strlen(Phpstan::root()) + 1),
        glob(Phpstan::root().'/packages/contracts/src/{,*/,*/*/}*.php', GLOB_BRACE) ?: [],
    );

    expect(count($files))->toBeGreaterThan(30)
        ->and($files)->toContain('packages/contracts/src/Doctor/DoctorExitCode.php')
        ->and(filesWritingExitCodes($files))->toBe([])
        ->and(filesWritingExitCodes($contracts))->toBe(['packages/contracts/src/Errors/ExitCode.php']);
});

it('defines the exit codes in the catalog\'s ExitCode, and DoctorExitCode only names some of them', function (): void {
    $enums = [];

    foreach (glob(Phpstan::root().'/packages/*/src/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE) ?: [] as $file) {
        if (preg_match_all('/^enum\s+(\w*Exit\w*)/m', (string) file_get_contents($file), $matches) > 0) {
            array_push($enums, ...$matches[1]);
        }
    }

    sort($enums);
    $doctor = (string) file_get_contents(Phpstan::root().'/packages/contracts/src/Doctor/DoctorExitCode.php');

    expect($enums)->toBe(['DoctorExitCode', 'ExitCode'])
        ->and(preg_match_all('/^    case \w+ = ExitCode::\w+->value;$/m', $doctor))->toBe(4)
        ->and(array_values(array_intersect(integerLiterals('packages/contracts/src/Doctor/DoctorExitCode.php'), [0, ...range(64, 79)])))->toBe([]);
});
