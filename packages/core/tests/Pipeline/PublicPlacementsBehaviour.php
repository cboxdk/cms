<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Pipeline\Domain\Dto\EntryPlacements;
use Cbox\Cms\Core\Pipeline\Domain\PublicPlacements;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every PublicPlacements does, run against ReaderPublicPlacements on Postgres and
 * FakePublicPlacements, so the fake the pipeline's agent tests use cannot drift from what the
 * pipeline reads (GUARDRAILS 9). The time is NOW.
 */
trait PublicPlacementsBehaviour
{
    public const string NOW = '2026-03-10T12:00:00Z';

    /** An entry live in da through LIVE, at version 3, on a node outside the actor's regions, hidden in en, and withdrawn through WITHDRAWN at version 1. */
    public const string SHOWN_ENTRY = '0192a0c0-0000-7000-8000-0000000004e1';

    /** An entry scheduled through SCHEDULED, at version 2, whose window opens after NOW. */
    public const string SCHEDULED_ENTRY = '0192a0c0-0000-7000-8000-0000000004e2';

    /** An entry hidden through HIDDEN, at version 2, and through EXPIRED, at version 1, stored live with a window that ended before NOW. */
    public const string UNSHOWN_ENTRY = '0192a0c0-0000-7000-8000-0000000004e3';

    /** An entry with no placement. */
    public const string BARE_ENTRY = '0192a0c0-0000-7000-8000-0000000004e4';

    public const string LIVE = '0192a0c0-0000-7000-8000-0000000004f2';

    public const string WITHDRAWN = '0192a0c0-0000-7000-8000-0000000004f1';

    public const string SCHEDULED = '0192a0c0-0000-7000-8000-0000000004f3';

    public const string HIDDEN = '0192a0c0-0000-7000-8000-0000000004f5';

    public const string EXPIRED = '0192a0c0-0000-7000-8000-0000000004f4';

    /**
     * The placements under test, knowing the entries and placements of the constants above.
     */
    abstract protected function publicPlacements(): PublicPlacements;

    #[Test]
    public function it_shows_an_entry_through_a_live_placement_past_the_actor_s_regions_and_reads_every_placement_once(): void
    {
        $placements = $this->publicPlacements()->of(EntryId::fromString(self::SHOWN_ENTRY));

        Assert::assertEquals(PlacementId::fromString(self::LIVE), $placements->shown);
        Assert::assertSame(['placement:'.self::WITHDRAWN.'@1', 'placement:'.self::LIVE.'@3'], self::read($placements));
    }

    #[Test]
    public function it_shows_an_entry_through_a_placement_whose_window_opens_later(): void
    {
        $placements = $this->publicPlacements()->of(EntryId::fromString(self::SCHEDULED_ENTRY));

        Assert::assertEquals(PlacementId::fromString(self::SCHEDULED), $placements->shown);
        Assert::assertSame(['placement:'.self::SCHEDULED.'@2'], self::read($placements));
    }

    #[Test]
    public function it_does_not_show_an_entry_whose_placements_are_hidden_or_whose_window_has_ended(): void
    {
        $placements = $this->publicPlacements()->of(EntryId::fromString(self::UNSHOWN_ENTRY));

        Assert::assertNull($placements->shown);
        Assert::assertSame(['placement:'.self::EXPIRED.'@1', 'placement:'.self::HIDDEN.'@2'], self::read($placements));
    }

    #[Test]
    public function it_reads_nothing_for_an_entry_without_placements(): void
    {
        $placements = $this->publicPlacements()->of(EntryId::fromString(self::BARE_ENTRY));

        Assert::assertNull($placements->shown);
        Assert::assertTrue($placements->reads->isEmpty());
    }

    /**
     * @return list<string> each read as `<aggregate key>@<version>`
     */
    private static function read(EntryPlacements $placements): array
    {
        return array_map(
            static fn (ReadVersion $read): string => sprintf('%s@%s', $read->aggregate->aggregateKey(), $read->version->value ?? 'absent'),
            $placements->reads->reads,
        );
    }
}
