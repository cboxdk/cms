<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\ErrorCodeDeclaration;
use Cbox\Cms\Tests\Support\Arch\ErrorCodeScan;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Cbox\Cms\Tests\Support\Arch\SourceFile;

/*
 * Every error has a stable code from one catalog (PRD 6.1, GUARDRAILS 2.1, 7.2). Every code the
 * modules declare, as a CODE constant or a case of an error-code enum, has an entry in
 * Cbox\Cms\Contracts\Errors\ErrorCode, and every entry is declared or used by the modules' code,
 * or reserved in ErrorCodeScan::RESERVED for the task that will use it.
 * tests/Feature/Tooling/ErrorCodeScanTest.php covers what the scan finds and reports.
 */

arch('error catalog: every code of the modules has an entry, and every entry is used', function (): void {
    $packages = Codebase::root().'/packages/';
    $scan = ErrorCodeScan::scan(
        Codebase::packageTypes(),
        array_values(array_filter(Codebase::code(), static fn (SourceFile $file): bool => str_starts_with($file->path, $packages))),
    );
    $declaredBy = array_map(static fn (ErrorCodeDeclaration $declaration): string => $declaration->declaredBy, $scan->declarations);

    expect($declaredBy)->toContain(
        'Cbox\Cms\Contracts\Idempotency\Conflict::CODE',
        'Cbox\Cms\Contracts\Idempotency\InFlight::CODE',
        'Cbox\Cms\Core\Doctor\Domain\Checks\AppRoleCheck::CODE_SUPERUSER',
        'Cbox\Cms\Core\Registry\Domain\BuildErrorCode::DuplicateCommand',
        'Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode::InvalidConfig',
    );

    Rules::none(
        $scan->problems(array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases())),
        'The error catalog Cbox\Cms\Contracts\Errors\ErrorCode and the codes of the modules must match (PRD 6.1, GUARDRAILS 2.1):',
    );
});
