<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ErrorEntry;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\McpResponse;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\InFlight;

// How a surface answers a call that ends with an error code: it looks the code up in the error
// catalog and answers as the entry says, with the HTTP status, the exit code or the MCP response,
// the explanation and the link to the code's section of the error reference.

/**
 * The problem details document (RFC 9457) a REST surface would send for the code.
 *
 * @return array{type: string, title: string, status: int, detail: string, code: string, retryable: bool}
 */
function problemDetails(ErrorCode $code, string $cause): array
{
    $entry = $code->entry();

    return [
        'type' => $entry->docs(),
        'title' => $entry->explanation,
        'status' => $entry->http->value,
        'detail' => $cause,
        'code' => $entry->code->value,
        'retryable' => $entry->retryable,
    ];
}

it('answers a key that is still in flight with a conflict the client may retry', function (): void {
    $problem = problemDetails(ErrorCode::from(InFlight::CODE), 'Another call with the key order-1042 is still running.');

    expect($problem['status'])->toBe(409)
        ->and($problem['code'])->toBe('idempotency_in_flight')
        ->and($problem['retryable'])->toBeTrue()
        ->and($problem['type'])->toBe('docs/reference/errors.md#idempotency_in_flight')
        ->and(ErrorCode::IdempotencyInFlight->entry()->exit)->toBe(ExitCode::TempFail);
});

it('answers a key used with other content with a conflict that a retry does not fix', function (): void {
    $entry = ErrorCode::from(Conflict::CODE)->entry();

    expect($entry->http)->toBe(HttpStatus::Conflict)
        ->and($entry->exit->value)->toBe(65)
        ->and($entry->exit->symbol())->toBe('EX_DATAERR')
        ->and($entry->mcp)->toBe(McpResponse::ToolError)
        ->and($entry->mcp->jsonRpcCode())->toBeNull()
        ->and($entry->retryable)->toBeFalse();
});

it('answers a broken installation with an internal error on every surface', function (): void {
    $entry = ErrorCode::RegistryCacheMissing->entry();

    expect($entry->http->value)->toBe(500)
        ->and($entry->exit)->toBe(ExitCode::Config)
        ->and($entry->mcp->jsonRpcCode())->toBe(-32603)
        ->and($entry->explanation)->toContain('Run cms:build');
});

it('gives cms:doctor its exit codes', function (): void {
    expect(DoctorExitCode::Violation->value)->toBe(ErrorCode::DoctorPhpAllowUrlFopen->entry()->exit->value)
        ->and(DoctorExitCode::Unavailable->value)->toBe(ErrorCode::DoctorPostgresUnavailable->entry()->exit->value)
        ->and(DoctorExitCode::NotReady->value)->toBe(ErrorCode::DoctorPartitionRunwayShort->entry()->exit->value);
});

it('finds no entry for a code that is not in the catalog', function (): void {
    expect(ErrorCode::tryFrom('no_such_code'))->toBeNull()
        ->and(ErrorEntry::DOCS_PAGE)->toBe('docs/reference/errors.md');
});
