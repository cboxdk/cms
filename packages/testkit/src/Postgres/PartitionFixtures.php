<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Creates the partitions a test needs at the date its FakeClock shows (PRD 4, 4.2).
 *
 * Partitioned tables have no DEFAULT partition, so a write at a date without a partition fails.
 * The schema from the migrations has none; a test that writes to a partitioned table covers the
 * dates it writes at first:
 *
 *     $clock = new FakeClock(new DateTimeImmutable('2031-05-01T09:00:00Z'));
 *     app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P2D'));
 *
 * It runs `cms:partitions:maintain --from --to`, the command the application schedules, so the
 * partitions are made the same way as in production: as the owner role, for every table in
 * `cms.database.partitions.tables`. It creates only and removes nothing. The partitions stay until
 * the schema is rebuilt, and the harness truncates their rows after each test.
 */
#[Experimental]
final readonly class PartitionFixtures
{
    public const string COMMAND = 'cms:partitions:maintain';

    public function __construct(private Kernel $artisan) {}

    /**
     * Creates the partitions whose spans overlap [$from, $to] for every managed table.
     */
    public function cover(DateTimeInterface $from, DateTimeInterface $to): void
    {
        $status = $this->artisan->call(self::COMMAND, [
            '--from' => $this->iso($from),
            '--to' => $this->iso($to),
        ]);

        if ($status !== 0) {
            throw new AssertionFailedError(sprintf(
                "%s --from=%s --to=%s failed with exit code %d.\n%s",
                self::COMMAND,
                $this->iso($from),
                $this->iso($to),
                $status,
                $this->artisan->output(),
            ));
        }
    }

    /**
     * Creates the partitions from the clock's time to $ahead after it.
     */
    public function coverClock(Clock $clock, DateInterval $ahead): void
    {
        $now = $clock->now();

        $this->cover($now, $now->add($ahead));
    }

    private function iso(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.uP');
    }
}
