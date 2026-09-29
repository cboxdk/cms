<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Receipts;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use DateTimeImmutable;
use DateTimeZone;

/*
 * The receipt DTOs and their parts (PRD 6.1, 8.4): which outcomes carry a changeset, the stored
 * receipt of a changeset, projection statuses, and the enums with their PRD names.
 */

function receiptChangeset(): ChangesetId
{
    return ChangesetId::fromString('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f');
}

function receiptPosition(): CommitPosition
{
    return new CommitPosition('4827');
}

function receiptProjection(string $name): ProjectionName
{
    return new ProjectionName($name);
}

/**
 * @param  callable(): mixed  $build
 */
function expectInvalidReceipt(callable $build, string $message): void
{
    expect($build)->toThrow(InvalidReceipt::class, $message);
}

it('builds a receipt for each committed outcome with its changeset', function (Outcome $outcome): void {
    $receipt = new Receipt($outcome, receiptChangeset(), WaitLevel::Origin, RetentionClass::Standard, [
        ProjectionStatus::pending(receiptProjection('fragments')),
    ], receiptPosition());

    expect($receipt->outcome)->toBe($outcome)
        ->and($receipt->changesetId)->toEqual(receiptChangeset())
        ->and($receipt->position)->toEqual(receiptPosition())
        ->and($receipt->waitLevel)->toBe(WaitLevel::Origin)
        ->and($receipt->retentionClass)->toBe(RetentionClass::Standard)
        ->and($receipt->projections)->toHaveCount(1)
        ->and($receipt->isCommitted())->toBeTrue();
})->with([Outcome::Committed, Outcome::CommittedWaitTimeout]);

it('builds a receipt for each outcome that committed nothing, without a changeset', function (Outcome $outcome): void {
    $receipt = new Receipt($outcome, null, WaitLevel::Commit, RetentionClass::Evidence);

    expect($receipt->changesetId)->toBeNull()
        ->and($receipt->position)->toBeNull()
        ->and($receipt->projections)->toBe([])
        ->and($receipt->isCommitted())->toBeFalse();
})->with([Outcome::Rejected, Outcome::DryRun]);

it('fails with InvalidReceipt when a committed outcome has no changeset', function (Outcome $outcome): void {
    expectInvalidReceipt(
        static fn (): Receipt => new Receipt($outcome, null, WaitLevel::Commit, RetentionClass::Standard),
        "A {$outcome->value} receipt needs a ChangesetId",
    );
})->with([Outcome::Committed, Outcome::CommittedWaitTimeout]);

it('fails with InvalidReceipt when an outcome that committed nothing has a changeset', function (Outcome $outcome): void {
    expectInvalidReceipt(
        static fn (): Receipt => new Receipt($outcome, receiptChangeset(), WaitLevel::Commit, RetentionClass::Standard),
        "A {$outcome->value} receipt has no ChangesetId",
    );
})->with([Outcome::Rejected, Outcome::DryRun]);

it('fails with InvalidReceipt when an outcome that committed nothing has projection statuses', function (Outcome $outcome): void {
    expectInvalidReceipt(
        static fn (): Receipt => new Receipt($outcome, null, WaitLevel::Commit, RetentionClass::Standard, [
            ProjectionStatus::pending(receiptProjection('fragments')),
        ]),
        "A {$outcome->value} receipt has no projection statuses",
    );
})->with([Outcome::Rejected, Outcome::DryRun]);

it('fails with InvalidReceipt when a committed outcome has no commit position', function (Outcome $outcome): void {
    expectInvalidReceipt(
        static fn (): Receipt => new Receipt($outcome, receiptChangeset(), WaitLevel::Commit, RetentionClass::Standard),
        "A {$outcome->value} receipt needs the commit position of its changeset.",
    );
})->with([Outcome::Committed, Outcome::CommittedWaitTimeout]);

it('fails with InvalidReceipt when an outcome that committed nothing has a commit position', function (Outcome $outcome): void {
    expectInvalidReceipt(
        static fn (): Receipt => new Receipt($outcome, null, WaitLevel::Commit, RetentionClass::Standard, [], receiptPosition()),
        "A {$outcome->value} receipt has no position: the command committed nothing.",
    );
})->with([Outcome::Rejected, Outcome::DryRun]);

it('builds the same receipts through the named constructors', function (): void {
    $statuses = [ProjectionStatus::pending(receiptProjection('edge'))];

    expect(Receipt::committed(receiptChangeset(), WaitLevel::Edge, RetentionClass::Evidence, receiptPosition(), $statuses))
        ->toEqual(new Receipt(Outcome::Committed, receiptChangeset(), WaitLevel::Edge, RetentionClass::Evidence, $statuses, receiptPosition()))
        ->and(Receipt::committedWaitTimeout(receiptChangeset(), WaitLevel::Edge, RetentionClass::Evidence, receiptPosition(), $statuses))
        ->toEqual(new Receipt(Outcome::CommittedWaitTimeout, receiptChangeset(), WaitLevel::Edge, RetentionClass::Evidence, $statuses, receiptPosition()))
        ->and(Receipt::rejected(WaitLevel::Verified, RetentionClass::Standard))
        ->toEqual(new Receipt(Outcome::Rejected, null, WaitLevel::Verified, RetentionClass::Standard))
        ->and(Receipt::dryRun(WaitLevel::Propagated, RetentionClass::Standard))
        ->toEqual(new Receipt(Outcome::DryRun, null, WaitLevel::Propagated, RetentionClass::Standard));
});

it('sorts the projection statuses by name, so the order they were given in does not matter', function (): void {
    $search = ProjectionStatus::pending(receiptProjection('search'));
    $edge = ProjectionStatus::pending(receiptProjection('edge'));
    $acme = ProjectionStatus::pending(receiptProjection('acme.feed'));

    $receipt = Receipt::committed(receiptChangeset(), WaitLevel::Commit, RetentionClass::Standard, receiptPosition(), [$search, $edge, $acme]);

    expect($receipt->projections)->toBe([$acme, $edge, $search])
        ->and($receipt)->toEqual(Receipt::committed(receiptChangeset(), WaitLevel::Commit, RetentionClass::Standard, receiptPosition(), [$edge, $acme, $search]));
});

it('fails with InvalidReceipt when a projection is listed twice', function (): void {
    expectInvalidReceipt(
        static fn (): Receipt => Receipt::committed(receiptChangeset(), WaitLevel::Commit, RetentionClass::Standard, receiptPosition(), [
            ProjectionStatus::pending(receiptProjection('edge')),
            ProjectionStatus::acknowledged(receiptProjection('edge'), new DateTimeImmutable('2026-01-01T00:00:00Z')),
        ]),
        'The projection "edge" is listed twice',
    );
});

it('builds a stored receipt from the changeset, its retention class, its position and its projections', function (): void {
    $statuses = [ProjectionStatus::pending(receiptProjection('fragments'))];
    $stored = new StoredReceipt(receiptChangeset(), RetentionClass::Evidence, receiptPosition(), $statuses);

    expect($stored->changesetId)->toEqual(receiptChangeset())
        ->and($stored->retentionClass)->toBe(RetentionClass::Evidence)
        ->and($stored->position)->toEqual(receiptPosition())
        ->and($stored->projections)->toBe($statuses)
        ->and(new StoredReceipt(receiptChangeset(), RetentionClass::Standard, receiptPosition())->projections)->toBe([]);
});

it('sorts the projection statuses of a stored receipt by name', function (): void {
    $search = ProjectionStatus::pending(receiptProjection('search'));
    $edge = ProjectionStatus::pending(receiptProjection('edge'));
    $acme = ProjectionStatus::pending(receiptProjection('acme.feed'));

    expect(new StoredReceipt(receiptChangeset(), RetentionClass::Standard, receiptPosition(), [$search, $edge, $acme])->projections)
        ->toBe([$acme, $edge, $search]);
});

it('fails with InvalidReceipt when a stored receipt lists a projection twice', function (): void {
    expectInvalidReceipt(
        static fn (): StoredReceipt => new StoredReceipt(receiptChangeset(), RetentionClass::Standard, receiptPosition(), [
            ProjectionStatus::pending(receiptProjection('search')),
            ProjectionStatus::pending(receiptProjection('search')),
        ]),
        'The projection "search" is listed twice',
    );
});

it('keeps an acknowledgement time in UTC with its microseconds', function (): void {
    $status = ProjectionStatus::acknowledged(
        receiptProjection('fragments'),
        new DateTimeImmutable('2026-03-29T03:30:00.654321+02:00'),
    );

    expect($status->state)->toBe(ProjectionState::Acknowledged)
        ->and($status->acknowledgedAt?->getTimezone()->getName())->toBe('UTC')
        ->and($status->acknowledgedAt?->format('Y-m-d\TH:i:s.u'))->toBe('2026-03-29T01:30:00.654321');
});

it('has no acknowledgement time while pending', function (): void {
    $status = ProjectionStatus::pending(receiptProjection('fragments'));

    expect($status->state)->toBe(ProjectionState::Pending)
        ->and($status->acknowledgedAt)->toBeNull();
});

it('fails with InvalidReceipt when an acknowledged projection has no time', function (): void {
    expectInvalidReceipt(
        static fn (): ProjectionStatus => new ProjectionStatus(receiptProjection('edge'), ProjectionState::Acknowledged),
        'needs the time it acknowledged',
    );
});

it('fails with InvalidReceipt when a pending projection has a time', function (): void {
    expectInvalidReceipt(
        static fn (): ProjectionStatus => new ProjectionStatus(
            receiptProjection('edge'),
            ProjectionState::Pending,
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
        ),
        'has no acknowledgement time',
    );
});

it('fails with InvalidReceipt when an acknowledgement time is before 1970 or after 9999', function (DateTimeImmutable $at): void {
    expectInvalidReceipt(
        static fn (): ProjectionStatus => ProjectionStatus::acknowledged(receiptProjection('edge'), $at),
        'The time must be from 1970 to the end of 9999',
    );
})->with([
    'a microsecond before 1970' => fn (): DateTimeImmutable => new DateTimeImmutable('1969-12-31T23:59:59.999999Z'),
    'year 10000' => fn (): DateTimeImmutable => new DateTimeImmutable('2000-01-01T00:00:00Z')->setDate(10000, 1, 1),
    'year -1' => fn (): DateTimeImmutable => new DateTimeImmutable('2000-01-01T00:00:00Z')->setDate(-1, 1, 1),
]);

it('accepts the first and the last acknowledgement time', function (string $time): void {
    $status = ProjectionStatus::acknowledged(receiptProjection('edge'), new DateTimeImmutable($time, new DateTimeZone('UTC')));

    expect($status->acknowledgedAt?->format('Y-m-d\TH:i:s.u'))->toBe(substr($time, 0, 26));
})->with(['1970-01-01T00:00:00.000000Z', '9999-12-31T23:59:59.999999Z']);

it('accepts projection names that are dot-separated snake_case', function (string $name): void {
    expect((new ProjectionName($name))->value)->toBe($name);
})->with(['fragments', 'edge', 'search_index', 'acme.search', 'acme.feed_v2', str_repeat('a', ProjectionName::MAX_LENGTH)]);

it('rejects other projection names with InvalidReceipt', function (string $name): void {
    expectInvalidReceipt(static fn (): ProjectionName => new ProjectionName($name), 'A projection name is dot-separated snake_case');
})->with([
    'empty' => '',
    'upper case' => 'Fragments',
    'leading digit' => '1search',
    'hyphen' => 'search-index',
    'empty segment' => 'acme..search',
    'trailing dot' => 'acme.',
    'trailing newline' => "fragments\n",
    'too long' => str_repeat('a', ProjectionName::MAX_LENGTH + 1),
]);

it('compares projection names by value', function (): void {
    expect(new ProjectionName('edge')->equals(new ProjectionName('edge')))->toBeTrue()
        ->and(new ProjectionName('edge')->equals(new ProjectionName('search')))->toBeFalse();
});

it('names the outcomes, wait levels, retention classes and projection states as the PRD does', function (): void {
    expect(array_map(static fn (Outcome $case): string => $case->value, Outcome::cases()))
        ->toBe(['rejected', 'committed', 'committed_wait_timeout', 'dry_run'])
        ->and(array_map(static fn (WaitLevel $case): string => $case->value, WaitLevel::cases()))
        ->toBe(['commit', 'origin', 'edge', 'verified', 'propagated'])
        ->and(array_map(static fn (RetentionClass $case): string => $case->value, RetentionClass::cases()))
        ->toBe(['evidence', 'standard'])
        ->and(array_map(static fn (ProjectionState $case): string => $case->value, ProjectionState::cases()))
        ->toBe(['pending', 'acknowledged']);
});

it('marks only Committed and CommittedWaitTimeout as committed', function (): void {
    expect(array_values(array_filter(Outcome::cases(), static fn (Outcome $case): bool => $case->isCommitted())))
        ->toBe([Outcome::Committed, Outcome::CommittedWaitTimeout]);
});

it('keeps standard receipts for 7 days and leaves evidence to a policy', function (): void {
    expect(RetentionClass::Standard->days())->toBe(7)
        ->and(RetentionClass::STANDARD_DAYS)->toBe(7)
        ->and(RetentionClass::Evidence->days())->toBeNull();
});
