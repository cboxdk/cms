<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\SignalStop;
use Cbox\Cms\Cli\Boundary\SubscriptionArguments;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\Subscriptions\Actions\RunLane;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneReport;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneRun;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\Parking;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RefusedSubscription;
use Cbox\Cms\Core\Subscriptions\Domain\ServiceIdentityRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * `cms:events:run`: the event runner of one lane (PRD 7.4 to 7.8), which hands the events of the
 * event log to the lane's subscribers as the subscribers' service identity, with retries, backoff
 * and parking (the action RunLane). Run one process per lane; a second process of the same lane is
 * safe, because each subscription's batches hold its lock.
 *
 * Each subscription runs as its own actor (PRD 6.5 invariant 21): an addon's as the addon's service
 * actor of cbox-cms.addons.service_actors. An addon's subscription without a usable one is refused,
 * printed and logged with addon_service_actor_unavailable, and the others go on.
 *
 * It runs until SIGTERM or SIGINT and ends after the batch in progress, or, with --until-idle,
 * once the lane has nothing to handle and no try waits.
 *
 * Exit codes: 0 done, 64 invalid options, and the catalog's for a service actor it cannot run as:
 * 78 subscription_identity_invalid (not configured, unknown or not a service actor), 77
 * actor_not_active.
 */
#[Internal]
#[Description('Hand the events of the event log to the subscribers of a lane, with retries and parking')]
#[Signature('cms:events:run
        {--lane=critical : The lane to run}
        {--until-idle : Stop once the lane has nothing to handle}')]
final class RunEventsCommand extends Command
{
    public function handle(Container $container, LoggerInterface $log): int
    {
        try {
            $run = new LaneRun(SubscriptionArguments::lane($this->option('lane')), $this->option('until-idle') === true);
            $runner = $container->make(RunLane::class);
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return ExitCode::Usage->value;
        }

        $stop = new SignalStop;

        if (function_exists('pcntl_signal')) {
            $this->trap([SIGTERM, SIGINT], static function () use ($stop): void {
                $stop->request();
            });
        }

        try {
            $report = $runner->run($run, $stop);
        } catch (ServiceIdentityRefused $refused) {
            $log->error('The event runner cannot run as its service actor.', ['code' => $refused->errorCode, 'lane' => $run->lane->value]);
            $this->error($refused->getMessage());

            return ErrorCode::from($refused->errorCode)->entry()->exit->value;
        }

        $this->report($report, $log);

        return self::SUCCESS;
    }

    private function report(LaneReport $report, LoggerInterface $log): void
    {
        foreach ($report->parked as $parking) {
            $this->line(sprintf('parked %s %s after %d tries', $parking->subscription->value, $parking->aggregate->toString(), $parking->attempts));
        }

        foreach ($report->refused as $refused) {
            $this->error(sprintf('refused %s: %s', $refused->subscription->value, $refused->reason));
            $log->error('The event runner refused a subscription without an actor it may run as.', [
                'code' => $refused->code,
                'lane' => $report->lane->value,
                'subscription' => $refused->subscription->value,
            ]);
        }

        foreach ($report->releasedWithoutEvent as $aggregate) {
            $this->line(sprintf('released %s without an event to hand', $aggregate->toString()));
        }

        $this->info(sprintf(
            'Lane %s ran as %s: %d handled, %d passed, %d failed tries, %d parked, %d released, %d batches, %d busy.',
            $report->lane->value,
            $report->actor->toString(),
            $report->handled,
            $report->passed,
            $report->failures,
            count($report->parked),
            $report->released,
            $report->batches,
            $report->busy,
        ));

        $log->info('The event runner ran.', [
            'lane' => $report->lane->value,
            'actor' => $report->actor->toString(),
            'handled' => $report->handled,
            'failures' => $report->failures,
            'parked' => array_map(static fn (Parking $parking): string => $parking->subscription->value.' '.$parking->aggregate->toString(), $report->parked),
            'released' => $report->released,
            'refused' => array_map(static fn (RefusedSubscription $refused): string => $refused->subscription->value, $report->refused),
        ]);
    }
}
