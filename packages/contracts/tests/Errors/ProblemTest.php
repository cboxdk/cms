<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Errors;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\InvalidProblem;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;

/*
 * A problem details document (RFC 9457, PRD 8.8) answers its code as the error catalog says
 * (PRD 6.1): the type, the status and whether it is retryable come from the code's entry, and a
 * document that says otherwise is refused.
 */

function problemFor(ErrorCode $code, string $type = '', ?int $status = null, ?bool $retryable = null, string $title = 'A title.', string $detail = 'A cause.', ?string $instance = null): Problem
{
    $entry = $code->entry();

    return new Problem($type === '' ? $entry->docs() : $type, $title, $status ?? $entry->http->value, $detail, $instance, $code, $retryable ?? $entry->retryable);
}

it('takes the type, the title, the status and whether it is retryable from the code\'s entry', function (): void {
    $errors = [new CatalogError(ErrorCode::ValidationFailed, new FieldPath('fields', 'title'), 'The title is empty.')];
    $problem = Problem::of(ErrorCode::ValidationFailed, 'One field breaks its rule.', $errors, '/v1/entries/1');

    expect($problem->type)->toBe('docs/reference/errors.md#validation_failed')
        ->and($problem->title)->toBe(ErrorCode::ValidationFailed->entry()->explanation)
        ->and($problem->status)->toBe(422)
        ->and($problem->retryable)->toBeFalse()
        ->and($problem->code)->toBe(ErrorCode::ValidationFailed)
        ->and($problem->detail)->toBe('One field breaks its rule.')
        ->and($problem->instance)->toBe('/v1/entries/1')
        ->and($problem->errors)->toBe($errors)
        ->and(Problem::of(ErrorCode::IdempotencyInFlight, 'Still running.')->retryable)->toBeTrue()
        ->and(Problem::of(ErrorCode::IdempotencyInFlight, 'Still running.')->errors)->toBe([])
        ->and(Problem::of(ErrorCode::IdempotencyInFlight, 'Still running.')->instance)->toBeNull();
});

it('keeps a title that is not the entry\'s explanation, which may change between versions', function (): void {
    expect(problemFor(ErrorCode::VersionConflict, title: 'Conflict')->title)->toBe('Conflict');
});

it('refuses a type, a status or a retryable that is not the catalog\'s', function (): void {
    expect(static fn (): Problem => problemFor(ErrorCode::VersionConflict, type: 'about:blank'))
        ->toThrow(InvalidProblem::class, 'The type of a problem with the code version_conflict is "docs/reference/errors.md#version_conflict", the section of the error reference for the code, got "about:blank".')
        ->and(static fn (): Problem => problemFor(ErrorCode::VersionConflict, status: 400))
        ->toThrow(InvalidProblem::class, 'The status of a problem with the code version_conflict is 409, as the error catalog says, got 400.')
        ->and(static fn (): Problem => problemFor(ErrorCode::VersionConflict, retryable: true))
        ->toThrow(InvalidProblem::class, 'A problem with the code version_conflict is not retryable, as the error catalog says.')
        ->and(static fn (): Problem => problemFor(ErrorCode::IdempotencyInFlight, retryable: false))
        ->toThrow(InvalidProblem::class, 'A problem with the code idempotency_in_flight is retryable, as the error catalog says.');
});

it('refuses a title, a detail or an instance without text', function (string $member, string $title, string $detail, ?string $instance): void {
    expect(static fn (): Problem => problemFor(ErrorCode::VersionConflict, title: $title, detail: $detail, instance: $instance))
        ->toThrow(InvalidProblem::class, sprintf('The %s of a problem with the code version_conflict is empty; it needs text.', $member));
})->with([
    'title' => ['title', ' ', 'A cause.', null],
    'detail' => ['detail', 'A title.', "\n", null],
    'instance' => ['instance', 'A title.', 'A cause.', ''],
]);

it('shows a long type cut and escaped in the message', function (): void {
    expect(static fn (): Problem => problemFor(ErrorCode::VersionConflict, type: str_repeat('a', 70)."\n"))
        ->toThrow(InvalidProblem::class, 'got "'.str_repeat('a', 64).'...".');
});

it('shows a refused type in full up to 64 bytes and cut after that', function (): void {
    $full = str_repeat('t', 64);

    expect(InvalidProblem::type(ErrorCode::JsonInvalid, $full)->getMessage())->toEndWith("got \"{$full}\".")
        ->and(InvalidProblem::type(ErrorCode::JsonInvalid, $full.'u')->getMessage())->toEndWith("got \"{$full}...\".");
});
