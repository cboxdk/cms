<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Dto\ParkedCount;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\EventLogProbe;
use Override;

/**
 * No subscription has parked aggregates (PRD 7.8, 7.12): an aggregate is parked for a subscription
 * after its event failed as often as the runner tries, and its later events wait with it, so the
 * subscription's projection of that aggregate stays old until an operator fixes the fault and
 * releases it. The count is the alarm of PRD 7.8. A released aggregate that the runner has not yet
 * handled does not count. It does not block: the other aggregates are still handled.
 */
#[Internal]
final readonly class ParkedAggregatesCheck implements DoctorCheck
{
    public const string ID = 'events.parked';

    public const string CODE = 'doctor_events_parked';

    public function __construct(private EventLogProbe $events) {}

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
            $parked = $this->events->parked();
        } catch (ProbeFailed $failed) {
            return CheckResult::fail(
                $this->id(),
                false,
                $failed->kind,
                EventLagCheck::CODE_UNREADABLE,
                'The doctor could not read the parked aggregates.',
                $failed->cause,
                'Run the core\'s migrations as the owner role, then run cms:doctor again.',
            );
        }

        if ($parked === []) {
            return CheckResult::pass($this->id(), false, 'No subscription has parked an aggregate.');
        }

        $total = array_sum(array_map(static fn (ParkedCount $count): int => $count->count, $parked));

        return CheckResult::fail(
            $this->id(),
            false,
            FailureKind::Violation,
            self::CODE,
            sprintf(
                '%d %s parked: %s failed as often as the runner tries, and %s later events wait until %s released.',
                $total,
                $total === 1 ? 'aggregate is' : 'aggregates are',
                $total === 1 ? 'its event' : 'their events',
                $total === 1 ? 'its' : 'their',
                $total === 1 ? 'it is' : 'they are',
            ),
            sprintf('%s.', implode(', ', array_map(
                static fn (ParkedCount $count): string => sprintf('%s has %d parked', $count->subscription->value, $count->count),
                $parked,
            ))),
            'List them with php artisan cms:events:parked, fix the fault the subscriber failed on, and release each with php artisan cms:events:release.',
        );
    }
}
