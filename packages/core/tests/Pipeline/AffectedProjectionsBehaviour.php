<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Core\Pipeline\Domain\AffectedProjections;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeNoted;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbePublished;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every AffectedProjections does, run against RegistryAffectedProjections and
 * FakeAffectedProjections, so the fake a commit's tests use cannot drift from the registry the
 * application reads (GUARDRAILS 9).
 */
trait AffectedProjectionsBehaviour
{
    /**
     * The implementation under test, knowing these subscribers: one with the projection origin and
     * one with the projection search for ProbePublished, one with the projection origin again for
     * ProbePublished, and one without a projection for ProbeNoted.
     */
    abstract protected function affectedProjections(): AffectedProjections;

    #[Test]
    public function it_lists_the_pending_projections_of_the_subscribers_that_handle_an_event(): void
    {
        Assert::assertSame(
            ['origin pending', 'search pending'],
            $this->describe($this->affectedProjections()->pendingFor([new ProbePublished])),
        );
    }

    #[Test]
    public function it_lists_no_projection_for_an_event_no_subscriber_acknowledges(): void
    {
        $projections = $this->affectedProjections();

        Assert::assertSame([], $projections->pendingFor([new ProbeNoted]));
        Assert::assertSame([], $projections->pendingFor([]));
    }

    #[Test]
    public function it_lists_each_projection_once_for_the_events_of_one_changeset(): void
    {
        Assert::assertSame(
            ['origin pending', 'search pending'],
            $this->describe($this->affectedProjections()->pendingFor([new ProbeNoted, new ProbePublished(1), new ProbePublished(2)])),
        );
    }

    /**
     * @param  list<ProjectionStatus>  $statuses
     * @return list<string>
     */
    private function describe(array $statuses): array
    {
        return array_map(static fn (ProjectionStatus $status): string => $status->projection->value.' '.$status->state->value, $statuses);
    }
}
