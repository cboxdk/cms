<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\OperatorProbe;
use Override;

/**
 * The installation has its operator (PRD 5.16, 3.3): the service actor cms:install created once,
 * which the maintenance commands run as. It exists, is of class service and is active. It does not
 * block: the kernel runs without it, but no maintenance command can, such as the one that creates
 * the first staff member.
 */
#[Internal]
final readonly class OperatorActorCheck implements DoctorCheck
{
    public const string ID = 'identity.operator_actor';

    public const string CODE_MISSING = 'doctor_operator_missing';

    public const string CODE_INVALID = 'doctor_operator_invalid';

    public const string CODE_UNREADABLE = 'doctor_operator_unreadable';

    public function __construct(private OperatorProbe $probe) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId(self::ID);
    }

    #[Override]
    public function blocking(): bool
    {
        return false;
    }

    #[Override]
    public function requires(): array
    {
        return [new CheckId(PostgresReachableCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $operator = $this->probe->operator();
        } catch (ProbeFailed $failed) {
            return CheckResult::fail(
                $this->id(),
                false,
                $failed->kind,
                self::CODE_UNREADABLE,
                'The doctor could not read the installation operator.',
                $failed->cause,
                'Run the core\'s migrations as the owner role in the maintenance process, then run cms:doctor again.',
            );
        }

        if (! $operator instanceof Actor) {
            return CheckResult::fail(
                $this->id(),
                false,
                FailureKind::Violation,
                self::CODE_MISSING,
                'The installation has no operator, so no maintenance command can run.',
                'The table installation has no row: cms:install has not run against this database.',
                'Run php artisan cms:install in the maintenance process, after the migrations and cms:partitions:maintain.',
            );
        }

        if ($operator->class !== ActorClass::Service || ! $operator->isActive()) {
            return CheckResult::fail(
                $this->id(),
                false,
                FailureKind::Violation,
                self::CODE_INVALID,
                'The installation operator is not an active service actor, so no maintenance command can run.',
                sprintf('The operator %s is a %s actor in the state %s.', $operator->id->toString(), $operator->class->value, $operator->state->value),
                'Find the changeset that changed the operator in the audit and undo it; the operator is created once, by cms:install, and is never replaced.',
            );
        }

        return CheckResult::pass($this->id(), false, sprintf('The installation operator %s is an active service actor.', $operator->id->toString()));
    }
}
