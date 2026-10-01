<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Core\Addons\Actions\ResolveSubscriberActor;
use Cbox\Cms\Core\Addons\Domain\Dto\ServiceActors;
use Cbox\Cms\Core\Subscriptions\Actions\RunLane;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneReport;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneRun;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\SubscriberBinding;
use Cbox\Cms\Core\Subscriptions\Domain\RunnerStop;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakeLaneSubscribers;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakeSubscriptionLog;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\StopAfterRounds;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\RecordingSubscriber;
use Cbox\Cms\Core\Tests\Subscriptions\Fixtures\SubscriberJournal;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;

/**
 * The runner's action tests' world (GUARDRAILS 9): the fake log, pacing, identity and access
 * contexts, an active service actor, the addons' service actors by namespace, and the recording
 * subscriber on the critical lane as test.counters.
 */
final class LaneWorld
{
    public readonly FakeSubscriptionLog $log;

    public readonly FakePacing $pacing;

    public readonly FakeIdentity $identity;

    public readonly SubscriberJournal $journal;

    public readonly Actor $service;

    public readonly FakeAccessContexts $contexts;

    /** @var array<string, ActorId> the addons' service actors by namespace */
    public array $addonActors = [];

    /** @var list<SubscriberBinding> */
    public array $bindings;

    public function __construct()
    {
        $clock = new FakeClock;
        $this->log = new FakeSubscriptionLog($clock);
        $this->pacing = new FakePacing;
        $this->identity = new FakeIdentity($clock);
        $this->journal = new SubscriberJournal;
        $this->contexts = new FakeAccessContexts;
        $this->service = $this->identity->addActor(ActorClass::Service);
        $this->bindings = [RecordingSubscriber::bound($this->journal)];
    }

    /**
     * Commits one counter.raised per "<id>@<version>", in a transaction of its own.
     *
     * @return list<StoredEvent>
     */
    public function raise(string ...$events): array
    {
        return $this->log->record(EventStream::Interactive, array_map(static function (string $event): CounterRaised {
            [$id, $version] = explode('@', $event);

            return CounterRaised::of($id, (int) $version);
        }, array_values($events)));
    }

    public function runner(?RunnerSettings $settings = null): RunLane
    {
        return new RunLane(
            $this->log,
            new FakeLaneSubscribers($this->bindings),
            $this->identity,
            $settings ?? $this->settings(),
            $this->pacing,
            $this->contexts,
            new ResolveSubscriberActor(new ServiceActors($this->addonActors), $this->identity),
        );
    }

    public function settings(int $batchSize = 100, int $maxAttempts = 3, int $batchBudgetMs = 1_000): RunnerSettings
    {
        return new RunnerSettings($this->service->id, $batchSize, $batchBudgetMs, $maxAttempts, 100, 400, 200);
    }

    public function untilIdle(?RunnerSettings $settings = null, ?RunnerStop $stop = null): LaneReport
    {
        return $this->runner($settings)->run(new LaneRun(Lane::Critical, untilIdle: true), $stop ?? new StopAfterRounds);
    }
}
