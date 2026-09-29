<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Errors;

use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ErrorEntry;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\McpResponse;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\InFlight;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use InvalidArgumentException;

/*
 * The error catalog (PRD 6.1, GUARDRAILS 2.1, 7.2): the form of its codes and cases, the entries
 * of the codes M1 adds, and the values of the types an entry is made of.
 */

it('names every case after its code in PascalCase, and every code has the form of a code', function (ErrorCode $code): void {
    $pascal = str_replace(' ', '', ucwords(str_replace('_', ' ', $code->value)));

    expect($code->name)->toBe($pascal)
        ->and(preg_match(ErrorCode::PATTERN, $code->value))->toBe(1)
        ->and(strlen($code->value))->toBeLessThanOrEqual(63);
})->with(ErrorCode::cases());

it('gives every code an entry for itself, with an explanation and a section of the error reference', function (ErrorCode $code): void {
    $entry = $code->entry();

    expect($entry->code)->toBe($code)
        ->and($entry->explanation)->toEndWith('.')
        ->and($entry->docs())->toBe('docs/reference/errors.md#'.$code->value)
        ->and($code->docs())->toBe($entry->docs());
})->with(ErrorCode::cases());

it('lists the codes sorted, so the catalog reads like its reference', function (): void {
    $values = array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases());
    $sorted = $values;
    sort($sorted, SORT_STRING);

    expect($values)->toBe($sorted)
        ->and(array_unique($values))->toBe($values);
});

it('keeps the codes the modules had before the catalog, with no rename', function (): void {
    expect(ErrorCode::from(Conflict::CODE))->toBe(ErrorCode::IdempotencyConflict)
        ->and(ErrorCode::from(PartitionMissing::CODE))->toBe(ErrorCode::PartitionMissing)
        ->and(ErrorCode::tryFrom('generate_handle_collision'))->toBeNull();
});

it('has the codes M1 adds, with what each surface answers', function (ErrorCode $code, HttpStatus $http, ExitCode $exit, McpResponse $mcp, bool $retryable): void {
    $entry = $code->entry();

    expect([$entry->http, $entry->exit, $entry->mcp, $entry->retryable])->toBe([$http, $exit, $mcp, $retryable]);
})->with([
    'in flight' => [ErrorCode::from(InFlight::CODE), HttpStatus::Conflict, ExitCode::TempFail, McpResponse::ToolError, true],
    'idempotency conflict' => [ErrorCode::IdempotencyConflict, HttpStatus::Conflict, ExitCode::DataErr, McpResponse::ToolError, false],
    'version conflict' => [ErrorCode::VersionConflict, HttpStatus::Conflict, ExitCode::DataErr, McpResponse::ToolError, false],
    'validation failed' => [ErrorCode::ValidationFailed, HttpStatus::UnprocessableContent, ExitCode::DataErr, McpResponse::ToolError, false],
    'unauthorized' => [ErrorCode::Unauthorized, HttpStatus::Forbidden, ExitCode::NoPerm, McpResponse::ToolError, false],
    'actor not active' => [ErrorCode::ActorNotActive, HttpStatus::Forbidden, ExitCode::NoPerm, McpResponse::ToolError, false],
    'dry run' => [ErrorCode::DryRun, HttpStatus::Ok, ExitCode::Ok, McpResponse::Result, false],
    'partition missing' => [ErrorCode::PartitionMissing, HttpStatus::ServiceUnavailable, ExitCode::TempFail, McpResponse::InternalError, true],
]);

it('gives each code of a doctor check an exit code cms:doctor exits with', function (ErrorCode $code): void {
    $exit = $code->entry()->exit->value;

    expect(DoctorExitCode::tryFrom($exit))->not->toBeNull()
        ->and($exit)->not->toBe(DoctorExitCode::Ok->value);
})->with(array_values(array_filter(ErrorCode::cases(), static fn (ErrorCode $code): bool => str_starts_with($code->value, 'doctor_'))));

it('marks as retryable exactly the codes whose exit code is a temporary failure', function (ErrorCode $code): void {
    $entry = $code->entry();

    expect($entry->retryable)->toBe($entry->exit === ExitCode::TempFail);
})->with(ErrorCode::cases());

it('takes the exit codes of cms:doctor from the catalog', function (): void {
    expect(DoctorExitCode::Ok->value)->toBe(ExitCode::Ok->value)
        ->and(DoctorExitCode::Unavailable->value)->toBe(ExitCode::TempFail->value)
        ->and(DoctorExitCode::Violation->value)->toBe(ExitCode::Config->value)
        ->and(DoctorExitCode::NotReady->value)->toBe(ExitCode::NotReady->value);
});

it('names every exit code as sysexits.h does', function (ExitCode $exit, int $value, string $symbol): void {
    expect($exit->value)->toBe($value)
        ->and($exit->symbol())->toBe($symbol);
})->with([
    [ExitCode::Ok, 0, 'EX_OK'],
    [ExitCode::Usage, 64, 'EX_USAGE'],
    [ExitCode::DataErr, 65, 'EX_DATAERR'],
    [ExitCode::NoInput, 66, 'EX_NOINPUT'],
    [ExitCode::NoUser, 67, 'EX_NOUSER'],
    [ExitCode::NoHost, 68, 'EX_NOHOST'],
    [ExitCode::Unavailable, 69, 'EX_UNAVAILABLE'],
    [ExitCode::Software, 70, 'EX_SOFTWARE'],
    [ExitCode::OsErr, 71, 'EX_OSERR'],
    [ExitCode::OsFile, 72, 'EX_OSFILE'],
    [ExitCode::CantCreat, 73, 'EX_CANTCREAT'],
    [ExitCode::IoErr, 74, 'EX_IOERR'],
    [ExitCode::TempFail, 75, 'EX_TEMPFAIL'],
    [ExitCode::Protocol, 76, 'EX_PROTOCOL'],
    [ExitCode::NoPerm, 77, 'EX_NOPERM'],
    [ExitCode::Config, 78, 'EX_CONFIG'],
    [ExitCode::NotReady, 79, 'NOT_READY'],
]);

it('gives every HTTP status its reason phrase', function (HttpStatus $status, int $value, string $reason): void {
    expect($status->value)->toBe($value)
        ->and($status->reason())->toBe($reason);
})->with([
    [HttpStatus::Ok, 200, 'OK'],
    [HttpStatus::Forbidden, 403, 'Forbidden'],
    [HttpStatus::Conflict, 409, 'Conflict'],
    [HttpStatus::UnprocessableContent, 422, 'Unprocessable Content'],
    [HttpStatus::InternalServerError, 500, 'Internal Server Error'],
    [HttpStatus::ServiceUnavailable, 503, 'Service Unavailable'],
]);

it('gives every MCP response its JSON-RPC code and description', function (McpResponse $response, ?int $code, string $description): void {
    expect($response->jsonRpcCode())->toBe($code)
        ->and($response->describe())->toBe($description);
})->with([
    [McpResponse::Result, null, 'a tool result'],
    [McpResponse::ToolError, null, 'a tool result with isError set'],
    [McpResponse::InternalError, -32603, 'the JSON-RPC error -32603, Internal error'],
]);

it('refuses an entry without an explanation, or one that does not end a sentence', function (string $explanation): void {
    expect(fn (): ErrorEntry => new ErrorEntry(ErrorCode::DryRun, HttpStatus::Ok, ExitCode::Ok, McpResponse::Result, false, $explanation))
        ->toThrow(InvalidArgumentException::class, 'The explanation of dry_run must be one or more sentences, ending with a full stop.');
})->with(['empty' => [''], 'blank' => ["  \n"], 'no full stop' => ['Nothing was committed']]);

it('accepts an explanation that ends a sentence', function (): void {
    expect(new ErrorEntry(ErrorCode::DryRun, HttpStatus::Ok, ExitCode::Ok, McpResponse::Result, false, 'Nothing was committed.')->explanation)->toBe('Nothing was committed.');
});
