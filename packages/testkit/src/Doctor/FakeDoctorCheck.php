<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Doctor;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;

/**
 * A doctor check that passes or fails as the test says, and counts its runs:
 *
 *     $postgres = FakeDoctorCheck::failing(new CheckId('fake.postgres'), FailureKind::Unavailable);
 *     $roles = FakeDoctorCheck::passing(new CheckId('fake.roles'), requires: [new CheckId('fake.postgres')]);
 *
 * A failing fake fails with the code CODE and a cause and fix that name the check. passes() and
 * fails() change the outcome of the next run, for a test that repairs or breaks the state.
 */
#[Experimental]
final class FakeDoctorCheck implements DoctorCheck
{
    public const string CODE = 'fake_check_failed';

    private int $runs = 0;

    /**
     * @param  list<CheckId>  $requires
     */
    private function __construct(
        private readonly CheckId $check,
        private readonly bool $blocking,
        private readonly array $requires,
        private ?FailureKind $failure,
    ) {}

    /**
     * @param  list<CheckId>  $requires
     */
    public static function passing(CheckId $check, bool $blocking = true, array $requires = []): self
    {
        return new self($check, $blocking, $requires, null);
    }

    /**
     * @param  list<CheckId>  $requires
     */
    public static function failing(CheckId $check, FailureKind $failure = FailureKind::Violation, bool $blocking = true, array $requires = []): self
    {
        return new self($check, $blocking, $requires, $failure);
    }

    /** From the next run on, the check passes. */
    public function passes(): void
    {
        $this->failure = null;
    }

    /** From the next run on, the check fails with the kind. */
    public function fails(FailureKind $failure = FailureKind::Violation): void
    {
        $this->failure = $failure;
    }

    /** How often run() was called. */
    public function runs(): int
    {
        return $this->runs;
    }

    public function id(): CheckId
    {
        return $this->check;
    }

    public function blocking(): bool
    {
        return $this->blocking;
    }

    public function requires(): array
    {
        return $this->requires;
    }

    public function run(): CheckResult
    {
        $this->runs++;

        if (! $this->failure instanceof FailureKind) {
            return CheckResult::pass($this->check, $this->blocking, sprintf('The fake check %s passes.', $this->check->value));
        }

        return CheckResult::fail(
            $this->check,
            $this->blocking,
            $this->failure,
            self::CODE,
            sprintf('The fake check %s fails.', $this->check->value),
            sprintf('The test made %s fail as %s.', $this->check->value, $this->failure->value),
            sprintf('Make %s pass with passes().', $this->check->value),
        );
    }
}
