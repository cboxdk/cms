<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Ids;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for IdGenerator (GUARDRAILS 2.3 and 9). The FakeIdGenerator and every
 * real generator run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a new
 * instance of the generator from generator(), reading the time from the given clock:
 *
 *     final class SystemIdGeneratorContractTest extends TestCase
 *     {
 *         use IdGeneratorContract;
 *
 *         protected function generator(Clock $clock): IdGenerator
 *         {
 *             return new SystemIdGenerator($clock);
 *         }
 *     }
 *
 * The cases drive the clock with a FakeClock. They read the bits from the id's string, not only
 * through Uuid7, so a mistake in Uuid7 itself shows up here too.
 */
#[Experimental]
trait IdGeneratorContract
{
    /** RFC 9562: version 7 in the 13th hex digit, variant 0b10 in the top bits of the 17th. */
    private const string CANONICAL_V7 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private const int MANY = 10_000;

    /**
     * A new instance of the generator under test that reads the time from $clock.
     */
    abstract protected function generator(Clock $clock): IdGenerator;

    #[Test]
    public function ids_have_version_7_and_the_rfc_9562_variant(): void
    {
        $clock = new FakeClock;
        $generator = $this->generator($clock);

        for ($i = 0; $i < 200; $i++) {
            $value = $generator->next()->value;
            $bytes = (string) hex2bin(str_replace('-', '', $value));

            Assert::assertMatchesRegularExpression(self::CANONICAL_V7, $value);
            Assert::assertSame(16, strlen($bytes), "{$value} is not 128 bits.");
            Assert::assertSame(0x70, ord($bytes[6]) & 0xF0, "The version nibble of {$value} is not 7.");
            Assert::assertSame(0x80, ord($bytes[8]) & 0xC0, "The variant bits of {$value} are not 0b10.");

            if ($i % 10 === 0) {
                $clock->advance(new DateInterval('PT1S'));
            }
        }
    }

    #[Test]
    public function ids_made_while_time_stands_still_are_unique_and_sort_in_generation_order(): void
    {
        $generator = $this->generator(new FakeClock);

        $this->assertUniqueAndOrdered($this->take($generator, self::MANY, static function (): void {}));
    }

    #[Test]
    public function ids_made_while_time_moves_are_unique_and_sort_in_generation_order(): void
    {
        $clock = new FakeClock;
        $generator = $this->generator($clock);
        $step = new DateInterval('PT0S');
        $step->f = 0.000_137;

        $this->assertUniqueAndOrdered($this->take($generator, self::MANY, static function () use ($clock, $step): void {
            $clock->advance($step);
        }));
    }

    #[Test]
    public function the_embedded_unix_milliseconds_are_the_current_time(): void
    {
        $clock = new FakeClock;
        $generator = $this->generator($clock);

        $instants = [
            FakeClock::START,
            '2026-01-01T00:00:00.999999+00:00',
            '2026-01-01T00:00:01.000000+00:00',
            '2026-03-29T01:30:00.000500+02:00',
            '2031-07-14T12:00:00.456789+00:00',
            '9999-12-31T23:59:59.999999+00:00',
        ];

        foreach ($instants as $instant) {
            $now = $clock->set(new DateTimeImmutable($instant));
            $expected = (int) $now->format('U') * 1000 + intdiv((int) $now->format('u'), 1000);
            $id = $generator->next();

            Assert::assertSame($expected, $id->unixMilliseconds(), "At {$instant}.");
            Assert::assertSame(
                sprintf('%012x', $expected),
                substr(str_replace('-', '', $id->value), 0, 12),
                "The first 48 bits of {$id->value} are not the clock's milliseconds at {$instant}.",
            );
        }
    }

    #[Test]
    public function an_id_made_after_time_steps_back_one_second_sorts_after_the_one_before(): void
    {
        $clock = new FakeClock;
        $generator = $this->generator($clock);

        $first = $generator->next();
        $clock->set($clock->now()->modify('-1 second'));
        $second = $generator->next();

        Assert::assertGreaterThan(0, strcmp($second->value, $first->value), "{$second->value} does not sort after {$first->value}.");
        Assert::assertSame(
            $first->unixMilliseconds(),
            $second->unixMilliseconds(),
            'After a step back the generator keeps the last millisecond it used.',
        );
    }

    #[Test]
    public function ids_follow_the_time_again_once_it_passes_the_last_millisecond_used(): void
    {
        $clock = new FakeClock;
        $generator = $this->generator($clock);
        $start = $clock->now();

        $ids = [$generator->next()];
        $clock->set($start->modify('-5 seconds'));

        for ($i = 0; $i < 100; $i++) {
            $ids[] = $generator->next();
        }

        $later = $clock->set($start->modify('+1 second'));
        $ids[] = $generator->next();

        $this->assertUniqueAndOrdered($ids);
        Assert::assertSame(Uuid7::unixMillisecondsOf($later), array_last($ids)->unixMilliseconds());
    }

    #[Test]
    public function a_time_before_1970_is_refused(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('1969-12-31T23:59:59.999000+00:00'));

        try {
            $this->generator($clock)->next();
        } catch (InvalidUuid7 $invalid) {
            Assert::assertStringContainsString('-1', $invalid->getMessage());

            return;
        }

        Assert::fail('A UUIDv7 cannot hold a time before 1970, but the generator returned one.');
    }

    /**
     * @param  callable(): void  $between  called after each id
     * @return list<Uuid7>
     */
    private function take(IdGenerator $generator, int $count, callable $between): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $generator->next();
            $between();
        }

        return $ids;
    }

    /**
     * @param  list<Uuid7>  $ids  in generation order
     */
    private function assertUniqueAndOrdered(array $ids): void
    {
        $values = array_map(static fn (Uuid7 $id): string => $id->value, $ids);
        $sorted = $values;
        sort($sorted, SORT_STRING);

        Assert::assertCount(count($values), array_unique($values), 'The generator repeated an id.');
        Assert::assertSame($values, $sorted, 'Sorting the ids as strings changes their order.');

        foreach ($values as $index => $value) {
            Assert::assertMatchesRegularExpression(self::CANONICAL_V7, $value);

            if ($index > 0) {
                Assert::assertLessThan(0, strcmp($values[$index - 1], $value), "Id {$index} does not sort after the one before.");
            }
        }
    }
}
