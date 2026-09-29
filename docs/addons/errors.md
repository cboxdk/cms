---
title: Error codes
weight: 41
description: The error catalog, where every error of the kernel gets its stable code, and how a surface, an addon or a test reads an entry.
---

# Error codes

Every error of the kernel has a stable code from one catalog, the enum `Cbox\Cms\Contracts\Errors\ErrorCode` in the contracts module (PRD 6.1, GUARDRAILS 2.1). A code is lowercase words joined by underscores, such as `idempotency_conflict`, and it is never renamed. Its case is the code in PascalCase, `ErrorCode::IdempotencyConflict`. All the types on this page are `#[Experimental]`.

The [error reference](../reference/errors.md) lists every code. `composer docs:errors` writes it from the catalog, and `composer docs:check` fails when it differs, so the page and the code cannot drift apart.

## An entry

`ErrorCode::entry()` gives the code's `ErrorEntry`, which says how every surface answers a call that ends with the code:

- `http`, an `HttpStatus`: the status of the REST surface's problem details document, such as 409 Conflict. `reason()` gives the reason phrase.
- `exit`, an `ExitCode`: the exit code of the `cms:*` commands, from sysexits.h, such as 75 (`EX_TEMPFAIL`), or 79 when the kernel may start but is not ready. `symbol()` gives the name. `DoctorExitCode` takes its values from it.
- `mcp`, a `McpResponse`: a tool result, a tool result with `isError` set that the agent can act on, or the JSON-RPC error -32603 when the installation is at fault. `jsonRpcCode()` gives the JSON-RPC code, or null for a tool result.
- `retryable`: whether sending the same call again later can succeed, as for `idempotency_in_flight`, and not for `version_conflict`, which needs a new read first.
- `explanation`: what the code means and what to do, in plain language. The concrete cause of one failure is not in the entry; the error that carries the code gives it (GUARDRAILS 7.2).
- `docs()`: the code's section of the error reference, `docs/reference/errors.md#<code>`.

A class that fails with a code names it in a constant `CODE`, such as `Conflict::CODE` and `InFlight::CODE`, or in an enum of codes, and the catalog has an entry for it. The Arch suite fails when such a code has no entry, and when an entry is used by no code. An addon that needs a code of its own asks for it in the catalog; it does not invent one.

This example is a small REST surface: it looks the code up and builds the problem details document from the entry. It is in the `Unit` suite:

<!-- example: examples/Unit/Errors/ErrorCatalogTest.php -->
```php
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
```
