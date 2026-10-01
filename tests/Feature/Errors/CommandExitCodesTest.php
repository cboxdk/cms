<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Errors;

use Cbox\Cms\Cli\Boundary\PartitionReportOutput;
use Cbox\Cms\Cli\Console\BuildCommand;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\Partitions\Domain\DdlStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\FailedTable;
use Cbox\Cms\Core\Partitions\Domain\Dto\GaveUpStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use InvalidArgumentException;
use Psr\Log\NullLogger;

/*
 * The cms:* commands exit with the CLI exit code of the catalog's entry for the code they fail
 * with (PRD 6.1, GUARDRAILS 2.1).
 */

it('exits cms:generate and cms:schema:editor with the exit code of each code\'s entry', function (GenerateErrorCode $code): void {
    expect(GenerateCommand::exitCode($code))->toBe(ErrorCode::from($code->value)->entry()->exit->value);
})->with(GenerateErrorCode::cases());

it('exits cms:build with the exit code of the entries of its codes', function (BuildErrorCode $code): void {
    expect(ErrorCode::from($code->value)->entry()->exit->value)->toBe(BuildCommand::EXIT_INVALID_DECLARATIONS);
})->with(BuildErrorCode::cases());

it('exits cms:build with the exit code of the entry of its other code', function (): void {
    expect(ErrorCode::RegistryCacheUnwritable->entry()->exit->value)->toBe(BuildCommand::EXIT_UNWRITABLE);
});

it('exits cms:partitions:maintain with the catalog\'s exit code for each refusal, and usage for invalid options', function (InvalidArgumentException|LockTimeout|OwnerConnectionRequired|UnmanageableTable $refusal, ExitCode $exit): void {
    $output = new PartitionReportOutput(new NullLogger);

    expect($output->refused($refusal)->exit)->toBe($exit);
})->with([
    'invalid options' => [new InvalidArgumentException('Give both --from and --to, or neither.'), ExitCode::Usage],
    'lock timeout' => [LockTimeout::gaveUp(DdlStep::Create, 'events', 'events_p20260501', 3, '2s'), ErrorCode::PartitionLockTimeout->entry()->exit],
    'not the owner' => [OwnerConnectionRequired::appConnection('pgsql'), ErrorCode::PartitionOwnerRequired->entry()->exit],
    'unmanageable table' => [UnmanageableTable::inTransaction('pgsql_owner'), ErrorCode::PartitionTableUnmanageable->entry()->exit],
]);

it('exits cms:partitions:maintain after a run with the catalog\'s exit code of what failed, an unmanageable table before a lock', function (): void {
    $output = new PartitionReportOutput(new NullLogger);
    $gaveUp = GaveUpStep::of(LockTimeout::gaveUp(DdlStep::Create, 'events', 'events_p20260501', 3, '2s'));
    $failed = new FailedTable('audit', null, 'audit is missing', null);

    expect($output->of(new PartitionReport('cms_owner', [], [], [], [], []))->exit)->toBe(ExitCode::Ok)
        ->and($output->of(new PartitionReport('cms_owner', [], [], [$gaveUp], [], []))->exit)->toBe(ErrorCode::PartitionLockTimeout->entry()->exit)
        ->and($output->of(new PartitionReport('cms_owner', [], [], [$gaveUp], [$failed], []))->exit)->toBe(ErrorCode::PartitionTableUnmanageable->entry()->exit);
});
