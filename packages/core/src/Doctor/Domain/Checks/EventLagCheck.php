<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Core\Doctor\Domain\Dto\SubscriptionLag;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\EventLogProbe;
use DateTimeImmutable;
use DateTimeZone;
use Override;

/**
 * The event lag per lane (PRD 7.6, 7.12, GUARDRAILS 5): for every registered subscription, the age
 * of its oldest unhandled event at the Clock's time, against the lag target of its lane: 500 ms for
 * critical, 60 s for standard, 5 min for external and 2 s for revalidate. The background lane is
 * best effort; its lag is reported and never fails the check.
 *
 * The targets are the lanes' p95; the check fails as soon as one subscription's oldest unhandled
 * event is older than its lane's target, since that event is already later than the target and
 * every event behind it waits too. An event stays unhandled while no runner runs its lane, and
 * while a transaction that began before it commits holds the horizon below which the runners read
 * (PRD 7.4). It does not block: the kernel must start for the runners to catch up.
 */
#[Internal]
final readonly class EventLagCheck implements DoctorCheck
{
    public const string ID = 'events.lag';

    public const string CODE = 'doctor_events_lag';

    public const string CODE_UNREADABLE = 'doctor_event_log_unreadable';

    private const string TIME = 'Y-m-d\TH:i:s.v\Z';

    public function __construct(
        private EventLogProbe $events,
        private Clock $clock,
    ) {}

    /**
     * The lag target of the lane in milliseconds (PRD 7.6), or null for the background lane,
     * which is best effort.
     */
    public static function targetMilliseconds(Lane $lane): ?int
    {
        return match ($lane) {
            Lane::Critical => 500,
            Lane::Standard => 60_000,
            Lane::External => 300_000,
            Lane::Revalidate => 2_000,
            Lane::Background => null,
        };
    }

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
        return [new CheckId(PostgresReachableCheck::ID), new CheckId(RegistryCacheCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $subscriptions = $this->events->lag();
        } catch (ProbeFailed $failed) {
            return CheckResult::fail(
                $this->id(),
                false,
                $failed->kind,
                self::CODE_UNREADABLE,
                'The doctor could not read how far the subscriptions are behind.',
                $failed->cause,
                'Run the core\'s migrations as the owner role and php artisan cms:build, then run cms:doctor again.',
            );
        }

        if ($subscriptions === []) {
            return CheckResult::pass($this->id(), false, 'No subscriptions are registered, so no lane has events to handle.');
        }

        $now = $this->clock->now();
        $behind = array_values(array_filter($subscriptions, fn (SubscriptionLag $lag): bool => $this->isBehind($lag, $now)));

        if ($behind === []) {
            return CheckResult::pass($this->id(), false, sprintf('Every lane is within its lag target: %s.', $this->lanes($subscriptions, $now)));
        }

        $lanes = [];

        foreach ($behind as $lag) {
            $lanes[$lag->lane->value] = $lag->lane->value;
        }

        return CheckResult::fail(
            $this->id(),
            false,
            FailureKind::Violation,
            self::CODE,
            sprintf(
                'Subscriptions of the %s %s are further behind than the lag target, so their projections, such as purges and search, show old state for longer than promised.',
                count($lanes) === 1 ? 'lane' : 'lanes',
                implode(', ', $lanes),
            ),
            sprintf('At %s: %s.', $now->format(self::TIME), implode('; ', array_map(fn (SubscriptionLag $lag): string => $this->behind($lag, $now), $behind))),
            sprintf(
                'Check that a runner runs each of these lanes, php artisan cms:events:run --lane=<lane> (%s), and that no transaction holds back the horizon (postgres.oldest_xact).',
                implode(', ', $lanes),
            ),
        );
    }

    private function isBehind(SubscriptionLag $lag, DateTimeImmutable $now): bool
    {
        $target = self::targetMilliseconds($lag->lane);

        return $target !== null && $lag->oldestUnhandled instanceof DateTimeImmutable && $this->age($lag->oldestUnhandled, $now) > $target;
    }

    private function behind(SubscriptionLag $lag, DateTimeImmutable $now): string
    {
        $oldest = $lag->oldestUnhandled ?? $now;

        return sprintf(
            '%s (lane %s, target %d ms) has not handled an event of the %s stream from %s, %d ms old',
            $lag->subscription->value,
            $lag->lane->value,
            self::targetMilliseconds($lag->lane) ?? 0,
            $lag->stream->value ?? 'unknown',
            $oldest->setTimezone(new DateTimeZone('UTC'))->format(self::TIME),
            $this->age($oldest, $now),
        );
    }

    /**
     * Each lane that has subscriptions, in the order of Lane, with the age of its oldest unhandled
     * event.
     *
     * @param  non-empty-list<SubscriptionLag>  $subscriptions
     */
    private function lanes(array $subscriptions, DateTimeImmutable $now): string
    {
        $parts = [];

        foreach (Lane::cases() as $lane) {
            $inLane = array_values(array_filter($subscriptions, static fn (SubscriptionLag $lag): bool => $lag->lane === $lane));

            if ($inLane === []) {
                continue;
            }

            $oldest = null;

            foreach ($inLane as $lag) {
                if ($lag->oldestUnhandled instanceof DateTimeImmutable) {
                    $age = $this->age($lag->oldestUnhandled, $now);
                    $oldest = $oldest === null ? $age : max($oldest, $age);
                }
            }

            $target = self::targetMilliseconds($lane);
            $parts[] = sprintf(
                '%s (%d %s, %s, %s)',
                $lane->value,
                count($inLane),
                count($inLane) === 1 ? 'subscription' : 'subscriptions',
                $target === null ? 'best effort' : sprintf('target %d ms', $target),
                $oldest === null ? 'nothing unhandled' : sprintf('oldest unhandled event %d ms old', $oldest),
            );
        }

        return implode(', ', $parts);
    }

    /**
     * Milliseconds from the event to now; 0 for an event from a clock ahead of this one.
     */
    private function age(DateTimeImmutable $occurred, DateTimeImmutable $now): int
    {
        $milliseconds = intdiv((int) $now->format('Uu') - (int) $occurred->format('Uu'), 1000);

        return max(0, $milliseconds);
    }
}
