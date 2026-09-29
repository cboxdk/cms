<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Errors;

use Cbox\Cms\Cli\Console\BuildCommand;
use Cbox\Cms\Cli\Console\MaintainPartitionsCommand;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;

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

it('exits cms:build and cms:partitions:maintain with the exit code of the entries of their other codes', function (): void {
    expect(ErrorCode::RegistryCacheUnwritable->entry()->exit->value)->toBe(BuildCommand::EXIT_UNWRITABLE)
        ->and(ErrorCode::PartitionLockTimeout->entry()->exit->value)->toBe(MaintainPartitionsCommand::EXIT_LOCK_TIMEOUT)
        ->and(ErrorCode::PartitionOwnerRequired->entry()->exit->value)->toBe(MaintainPartitionsCommand::EXIT_NOT_OWNER)
        ->and(ErrorCode::PartitionTableUnmanageable->entry()->exit->value)->toBe(MaintainPartitionsCommand::EXIT_UNMANAGEABLE);
});
