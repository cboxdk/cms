<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Doctor;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The typed result of one doctor check (PRD 3.3, GUARDRAILS 7.2).
 *
 * Every result has the check's id, whether the check blocks the kernel from starting, its status
 * and an explanation in plain words. What else it carries depends on the status:
 *
 * - Pass: nothing else.
 * - Fail: the failure kind, which decides the exit code, an error code, the concrete cause and
 *   how to fix it. An error that only says what went wrong is a bug in the error.
 * - Skip: the cause, which names the check that did not pass.
 *
 * The constructor enforces this; use pass(), fail() and skip().
 */
#[Experimental]
final readonly class CheckResult
{
    /** An error code: lowercase snake_case with at least two words, like the codes of the error catalog. */
    public const string CODE_PATTERN = '/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)+\z/';

    public const int MAX_CODE_LENGTH = 63;

    public function __construct(
        public CheckId $id,
        public CheckStatus $status,
        public bool $blocking,
        public string $explanation,
        public ?FailureKind $failure = null,
        public ?string $code = null,
        public ?string $cause = null,
        public ?string $fix = null,
    ) {
        $this->text($id, 'explanation', $explanation);

        $failing = $status === CheckStatus::Fail;

        $this->presence($id, $status, 'failure kind', $failure instanceof FailureKind, $failing);
        $this->presence($id, $status, 'code', $code !== null, $failing);
        $this->presence($id, $status, 'cause', $cause !== null, $status !== CheckStatus::Pass);
        $this->presence($id, $status, 'fix', $fix !== null, $failing);

        if ($code !== null && (strlen($code) > self::MAX_CODE_LENGTH || preg_match(self::CODE_PATTERN, $code) !== 1)) {
            throw InvalidDoctorCheck::code($id, $code);
        }

        if ($cause !== null) {
            $this->text($id, 'cause', $cause);
        }

        if ($fix !== null) {
            $this->text($id, 'fix', $fix);
        }
    }

    public static function pass(CheckId $id, bool $blocking, string $explanation): self
    {
        return new self($id, CheckStatus::Pass, $blocking, $explanation);
    }

    public static function fail(
        CheckId $id,
        bool $blocking,
        FailureKind $failure,
        string $code,
        string $explanation,
        string $cause,
        string $fix,
    ): self {
        return new self($id, CheckStatus::Fail, $blocking, $explanation, $failure, $code, $cause, $fix);
    }

    public static function skip(CheckId $id, bool $blocking, string $explanation, string $cause): self
    {
        return new self($id, CheckStatus::Skip, $blocking, $explanation, cause: $cause);
    }

    public function passed(): bool
    {
        return $this->status === CheckStatus::Pass;
    }

    public function failed(): bool
    {
        return $this->status === CheckStatus::Fail;
    }

    private function presence(CheckId $id, CheckStatus $status, string $field, bool $present, bool $required): void
    {
        if ($present !== $required) {
            throw InvalidDoctorCheck::field($id, $status, $field, $required);
        }
    }

    private function text(CheckId $id, string $field, string $value): void
    {
        if (trim($value) === '') {
            throw InvalidDoctorCheck::emptyText($id, $field);
        }
    }
}
