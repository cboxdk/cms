<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Results;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\DryRunReport;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\InvalidWriteResult;
use Cbox\Cms\Contracts\Results\WriteResult;

/*
 * The result of a write (GUARDRAILS 2.1, PRD 6.1): the receipt's outcome decides whether it carries
 * catalog errors with field paths, or the report of a dry run: its plan, blast radius and diff.
 */

function resultChangeset(): ChangesetId
{
    return ChangesetId::fromString('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f');
}

function resultError(): CatalogError
{
    return new CatalogError(ErrorCode::ValidationFailed, new FieldPath('fields', 'title'), 'The title is empty.');
}

it('rejects with catalog errors and their field paths', function (): void {
    $second = new CatalogError(ErrorCode::VersionConflict, null, 'The variant moved on to version 4.');
    $result = WriteResult::rejected(Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard), resultError(), $second);

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and($result->errors)->toHaveCount(2)
        ->and($result->errors[0]->path?->toString())->toBe('fields.title')
        ->and($result->errors[1]->path)->toBeNull()
        ->and($result->errors[1]->code)->toBe(ErrorCode::VersionConflict)
        ->and($result->dryRun)->toBeNull()
        ->and(WriteResult::rejected(Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard), resultError())->errors)->toHaveCount(1)
        ->and(array_keys(WriteResult::rejected(Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard), resultError(), ...['more' => $second])->errors))->toBe([0, 1]);
});

it('commits with the receipt and nothing else', function (): void {
    foreach ([
        Receipt::committed(resultChangeset(), WaitLevel::Origin, RetentionClass::Standard),
        Receipt::committedWaitTimeout(resultChangeset(), WaitLevel::Edge, RetentionClass::Evidence),
    ] as $receipt) {
        $result = WriteResult::committed($receipt);

        expect($result->receipt)->toBe($receipt)
            ->and($result->outcome())->toBe($receipt->outcome)
            ->and($result->errors)->toBe([])
            ->and($result->dryRun)->toBeNull();
    }
});

it('returns the plan of a dry run with its blast radius and diff', function (): void {
    $report = DryRunReport::of(Plan::empty(), new ReadVersions);
    $result = WriteResult::dryRun(Receipt::dryRun(WaitLevel::Commit, RetentionClass::Standard), $report);

    expect($result->outcome())->toBe(Outcome::DryRun)
        ->and($result->dryRun)->toBe($report)
        ->and($result->errors)->toBe([]);
});

it('refuses parts that do not belong to the outcome', function (): void {
    $committed = Receipt::committed(resultChangeset(), WaitLevel::Commit, RetentionClass::Standard);
    $rejected = Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard);
    $dryRun = Receipt::dryRun(WaitLevel::Commit, RetentionClass::Standard);
    $report = DryRunReport::of(Plan::empty(), new ReadVersions);

    expect(static fn (): WriteResult => new WriteResult($rejected))->toThrow(InvalidWriteResult::class, 'A rejected write names at least one catalog error.')
        ->and(static fn (): WriteResult => new WriteResult($committed, [resultError()]))->toThrow(InvalidWriteResult::class, 'A committed write has no errors; only a rejected one does.')
        ->and(static fn (): WriteResult => new WriteResult($dryRun, [resultError()], $report))->toThrow(InvalidWriteResult::class, 'A dry_run write has no errors')
        ->and(static fn (): WriteResult => new WriteResult($dryRun))->toThrow(InvalidWriteResult::class, 'A dry run returns the plan it computed, with its blast radius and diff.')
        ->and(static fn (): WriteResult => new WriteResult($committed, dryRun: $report))->toThrow(InvalidWriteResult::class, 'A committed write returns no plan; only a dry run does.')
        ->and(static fn (): WriteResult => new WriteResult($rejected, [resultError()], $report))->toThrow(InvalidWriteResult::class, 'A rejected write returns no plan');
});

it('needs the cause of a catalog error', function (): void {
    expect(static fn (): CatalogError => new CatalogError(ErrorCode::ValidationFailed, null, ' '))
        ->toThrow(InvalidWriteResult::class, 'A catalog error with the code validation_failed needs its cause in plain language.');
});

it('writes a field path with dots between names and brackets around indexes', function (): void {
    $path = new FieldPath('blocks', 2, 'children', 0, 'text');

    expect($path->toString())->toBe('blocks[2].children[0].text')
        ->and($path->segments)->toBe(['blocks', 2, 'children', 0, 'text'])
        ->and(new FieldPath('fields', 'ext', 'app', 'tax_code')->toString())->toBe('fields.ext.app.tax_code')
        ->and(new FieldPath('expectedVersion')->toString())->toBe('expectedVersion')
        ->and(new FieldPath('fields')->then('ext', 'app')->then('tax_code')->toString())->toBe('fields.ext.app.tax_code')
        ->and(new FieldPath('items')->then(3)->toString())->toBe('items[3]')
        ->and(new FieldPath('_a', 'B9')->equals(new FieldPath('_a', 'B9')))->toBeTrue()
        ->and($path->equals(new FieldPath('blocks', 2)))->toBeFalse()
        ->and(static fn (): FieldPath => new FieldPath('fields', -1))->toThrow(InvalidWriteResult::class, 'A field path index is 0 or more, got -1.')
        ->and(static fn (): FieldPath => new FieldPath('9fields'))->toThrow(InvalidWriteResult::class, 'A field path name is a letter or an underscore followed by letters, digits and underscores, got "9fields".')
        ->and(static fn (): FieldPath => new FieldPath('a', 'b.c'))->toThrow(InvalidWriteResult::class, 'got "b.c"')
        ->and(static fn (): FieldPath => new FieldPath(''))->toThrow(InvalidWriteResult::class, 'got ""')
        ->and(new FieldPath('a', ...['x' => 'b', 'y' => 1])->segments)->toBe(['a', 'b', 1])
        ->and(new FieldPath('a')->then(...['x' => 'b', 'y' => 2])->segments)->toBe(['a', 'b', 2]);
});
