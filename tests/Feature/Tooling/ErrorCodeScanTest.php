<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\DeclaredType;
use Cbox\Cms\Tests\Support\Arch\ErrorCodeDeclaration;
use Cbox\Cms\Tests\Support\Arch\ErrorCodeScan;
use Cbox\Cms\Tests\Support\Arch\Fixtures\ErrorCodes\PlantedErrorCode;
use Cbox\Cms\Tests\Support\Arch\Fixtures\ErrorCodes\PlantedFailure;
use Cbox\Cms\Tests\Support\Arch\Fixtures\ErrorCodes\PlantedOutcome;
use Cbox\Cms\Tests\Support\Arch\Fixtures\ErrorCodes\PlantedSubFailure;
use Cbox\Cms\Tests\Support\Arch\Fixtures\ErrorCodes\PlantedSurface;
use Cbox\Cms\Tests\Support\Arch\SourceFile;
use ReflectionClass;

/*
 * The scan behind tests/Arch/ErrorCatalogTest.php (PRD 6.1, GUARDRAILS 2.1): what counts as a
 * declared code, and each problem it reports between the declarations and a catalog, on the
 * planted classes in tests/Support/Arch/Fixtures/ErrorCodes.
 */

/**
 * @param  class-string  $class
 */
function plantedType(string $class): DeclaredType
{
    $reflection = new ReflectionClass($class);

    return new DeclaredType($reflection->getNamespaceName(), $reflection->getShortName(), $reflection->isEnum() ? 'enum' : 'class', (string) $reflection->getFileName(), (int) $reflection->getStartLine());
}

function plantedScan(): ErrorCodeScan
{
    return ErrorCodeScan::scan(
        array_map(plantedType(...), [PlantedFailure::class, PlantedSubFailure::class, PlantedErrorCode::class, PlantedOutcome::class, PlantedSurface::class]),
        [SourceFile::read((string) new ReflectionClass(PlantedSurface::class)->getFileName())],
    );
}

/**
 * @return list<string>
 */
function plantedDeclarations(ErrorCodeScan $scan): array
{
    return array_map(static fn (ErrorCodeDeclaration $declaration): string => $declaration->declaredBy.' = '.$declaration->code, $scan->declarations);
}

/** The codes of the planted classes, as a catalog that has every one of them. */
const PLANTED_CATALOG = ['planted_failure', 'planted_other', 'planted_sub', 'planted_enum', 'dry_run'];

it('reads CODE and CODE_* string constants where they are declared, the cases of an enum named *ErrorCode, and the catalog cases a file names', function (): void {
    $scan = plantedScan();

    expect(plantedDeclarations($scan))->toBe([
        PlantedFailure::class.'::CODE = planted_failure',
        PlantedFailure::class.'::CODE_OTHER = planted_other',
        PlantedFailure::class.'::CODE_PATTERN = /\A[a-z]+\z/',
        PlantedSubFailure::class.'::CODE_SUB = planted_sub',
        PlantedErrorCode::class.'::Enum = planted_enum',
        PlantedErrorCode::class.'::Broken = Planted-Broken',
        'Cbox\Cms\Contracts\Errors\ErrorCode::DryRun = dry_run',
    ])
        ->and($scan->constants)->toBe([
            PlantedFailure::class.'::CODE',
            PlantedFailure::class.'::CODE_OTHER',
            PlantedFailure::class.'::CODE_PATTERN',
            PlantedFailure::class.'::CODE_LENGTH',
            PlantedSubFailure::class.'::CODE_SUB',
        ])
        ->and($scan->declarations[6]->where)->toBe('tests/Support/Arch/Fixtures/ErrorCodes/PlantedSurface.php');
});

it('reports nothing when the catalog has every declared code and each entry is used', function (): void {
    expect(plantedScan()->problems(PLANTED_CATALOG, [], [
        PlantedFailure::class.'::CODE_PATTERN' => 'a pattern',
        PlantedErrorCode::class.'::Broken' => 'a planted case that is no code',
    ]))->toBe([]);
});

it('reports a declared code the catalog lacks, and a value that is no code', function (): void {
    $catalog = array_values(array_diff(PLANTED_CATALOG, ['planted_sub']));

    expect(plantedScan()->problems($catalog, [], []))->toBe([
        sprintf('%s::CODE_PATTERN (tests/Support/Arch/Fixtures/ErrorCodes/PlantedFailure.php) is %s, which is no error code: codes are lowercase words joined by underscores.', PlantedFailure::class, var_export('/\A[a-z]+\z/', true)),
        sprintf('%s::CODE_SUB (tests/Support/Arch/Fixtures/ErrorCodes/PlantedSubFailure.php) declares planted_sub, which the error catalog Cbox\Cms\Contracts\Errors\ErrorCode has no entry for.', PlantedSubFailure::class),
        sprintf("%s::Broken (tests/Support/Arch/Fixtures/ErrorCodes/PlantedErrorCode.php) is 'Planted-Broken', which is no error code: codes are lowercase words joined by underscores.", PlantedErrorCode::class),
    ]);
});

it('reports an entry no code declares or uses, unless it is reserved', function (): void {
    $notCodes = [PlantedFailure::class.'::CODE_PATTERN' => 'a pattern', PlantedErrorCode::class.'::Broken' => 'planted'];

    expect(plantedScan()->problems([...PLANTED_CATALOG, 'planted_unused'], [], $notCodes))
        ->toBe(['The error catalog has an entry for planted_unused, but no code in the source declares or uses it.'])
        ->and(plantedScan()->problems([...PLANTED_CATALOG, 'planted_unused'], ['planted_unused' => 'a later task'], $notCodes))->toBe([]);
});

it('reports a reservation of a code that is used now or that the catalog lacks', function (): void {
    $notCodes = [PlantedFailure::class.'::CODE_PATTERN' => 'a pattern', PlantedErrorCode::class.'::Broken' => 'planted'];

    expect(plantedScan()->problems(PLANTED_CATALOG, ['dry_run' => 'the surfaces', 'planted_gone' => 'a later task'], $notCodes))->toBe([
        'dry_run is used now; remove it from ErrorCodeScan::RESERVED (the surfaces).',
        'planted_gone is reserved in ErrorCodeScan::RESERVED, but the error catalog has no entry for it.',
    ]);
});

it('reports an exception for a constant that no longer exists', function (): void {
    expect(plantedScan()->problems(PLANTED_CATALOG, [], [
        PlantedFailure::class.'::CODE_PATTERN' => 'a pattern',
        PlantedErrorCode::class.'::Broken' => 'planted',
        PlantedFailure::class.'::CODE_GONE' => 'removed',
    ]))->toBe([PlantedFailure::class.'::CODE_GONE is in ErrorCodeScan::NOT_CODES, but no such constant or case is declared.']);
});

it('keeps a reason for every reservation and exception of the repository', function (): void {
    expect(array_filter(ErrorCodeScan::RESERVED, static fn (string $reason): bool => trim($reason) === ''))->toBe([])
        ->and(array_filter(ErrorCodeScan::NOT_CODES, static fn (string $reason): bool => trim($reason) === ''))->toBe([])
        ->and(Codebase::relative(Codebase::root().'/packages/contracts/src/Errors/ErrorCode.php'))->toBe('packages/contracts/src/Errors/ErrorCode.php');
});
