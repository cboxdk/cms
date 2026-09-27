<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\UuidCreationRule;
use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 7, GUARDRAILS 2.3: only a Clock implementation reads the system clock.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
final class SystemClockRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_every_read_of_the_system_clock_outside_a_clock_and_tests(): void
    {
        // Not reported: hrtime() (line 39), each function with a timestamp or all its parts and
        // with unpacked arguments (lines 54 to 64), strtotime() with an absolute date, a base or
        // input (lines 79 to 81), absolute dates and input (lines 82 to 86), formats that reset or
        // set every field and a format that is not a constant (lines 95 to 101), Carbon with a
        // date and the methods that compare with another date (lines 129 to 137), another key of
        // $_SERVER (line 144), a new NativeClock, a DatePoint with a date and a MockClock (lines
        // 155 to 157), another trait (line 173), the Clock implementation and a closure in it
        // (lines 189 and 194), the tests (line 221) and the global namespace (line 228).
        self::assertSame([
            '31 cboxCms.systemClock',       // time();
            '32 cboxCms.systemClock',       // microtime(true);
            '33 cboxCms.systemClock',       // gettimeofday();
            '34 cboxCms.systemClock',       // uniqid();
            '35 cboxCms.systemClock',       // now();
            '36 cboxCms.systemClock',       // today();
            '37 cboxCms.systemClock',       // time(...);
            '38 cboxCms.systemClock',       // date(...);
            '44 cboxCms.systemClock',       // date('Y-m-d');
            '45 cboxCms.systemClock',       // date('Y-m-d', $maybe);
            '46 cboxCms.systemClock',       // gmdate('H');
            '47 cboxCms.systemClock',       // idate('Y');
            '48 cboxCms.systemClock',       // strftime('%Y');
            '49 cboxCms.systemClock',       // gmstrftime('%Y');
            '50 cboxCms.systemClock',       // getdate();
            '51 cboxCms.systemClock',       // localtime();
            '52 cboxCms.systemClock',       // mktime(0);
            '53 cboxCms.systemClock',       // gmmktime(0, 0, 0, 1, 1, $maybe);
            '69 cboxCms.systemClock',       // strtotime('+1 day');
            '70 cboxCms.systemClock',       // new DateTimeImmutable();
            '71 cboxCms.systemClock',       // new DateTimeImmutable('now', new DateTimeZone('UTC'));
            '72 cboxCms.systemClock',       // new DateTime('today');
            '73 cboxCms.systemClock',       // new DateTimeImmutable('');
            '74 cboxCms.systemClock',       // new DateTimeImmutable(timezone: new DateTimeZone('UTC'));
            '75 cboxCms.systemClock',       // new DateTimeImmutable($maybe);
            '76 cboxCms.systemClock',       // new DateTimeImmutable($early ? 'first monday of january' : '2026-01-01');
            '77 cboxCms.systemClock',       // date_create();
            '78 cboxCms.systemClock',       // date_create_immutable('tomorrow');
            '91 cboxCms.systemClock',       // DateTimeImmutable::createFromFormat('Y-m-d', '2026-01-01');
            '92 cboxCms.systemClock',       // DateTime::createFromFormat('H:i', '10:00');
            '93 cboxCms.systemClock',       // date_create_immutable_from_format('Y-m-d', '2026-01-01');
            '94 cboxCms.systemClock',       // date_create_from_format('Y-m-d H:i:\s', '2026-01-01 10:00:s');
            '106 cboxCms.systemClock',      // Carbon::now();
            '107 cboxCms.systemClock',      // Carbon::now(...);
            '108 cboxCms.systemClock',      // CarbonImmutable::today();
            '109 cboxCms.systemClock',      // LaravelCarbon::tomorrow();
            '110 cboxCms.systemClock',      // Carbon::yesterday();
            '111 cboxCms.systemClock',      // Carbon::createFromTime(10);
            '112 cboxCms.systemClock',      // Carbon::createFromTimeString('10:00');
            '113 cboxCms.systemClock',      // Carbon::createFromDate(2026, 1, 1);
            '114 cboxCms.systemClock',      // Carbon::create();
            '115 cboxCms.systemClock',      // Carbon::createMidnightDate(2026);
            '116 cboxCms.systemClock',      // Carbon::parse();
            '117 cboxCms.systemClock',      // CarbonImmutable::parse('next monday');
            '118 cboxCms.systemClock',      // new Carbon();
            '119 cboxCms.systemClock',      // Date::now();
            '120 cboxCms.systemClock',      // $factory->now();
            '121 cboxCms.systemClock',      // $carbonFactory->parse('now');
            '122 cboxCms.systemClock',      // $carbon->isPast();
            '123 cboxCms.systemClock',      // $carbon->diffInDays(...);
            '124 cboxCms.systemClock',      // $carbon->isToday();
            '125 cboxCms.systemClock',      // $carbon->isCurrentMonth();
            '126 cboxCms.systemClock',      // $carbon->diffForHumans();
            '127 cboxCms.systemClock',      // $carbon->diffInDays();
            '128 cboxCms.systemClock',      // $other->ago();
            '142 cboxCms.systemClock',      // $started = $_SERVER['REQUEST_TIME'];
            '143 cboxCms.systemClock',      // $precise = $_SERVER['REQUEST_TIME_FLOAT'];
            '149 cboxCms.systemClock',      // \Symfony\Component\Clock\now();
            '150 cboxCms.systemClock',      // $native->now();
            '151 cboxCms.systemClock',      // $monotonic->now();
            '152 cboxCms.systemClock',      // Clock::get();
            '153 cboxCms.systemClock',      // new Clock()->now();
            '154 cboxCms.systemClock',      // new DatePoint();
            '163 cboxCms.systemClock',      // use InteractsWithTime;
            '168 cboxCms.systemClock',      // use ClockAwareTrait;
            '203 cboxCms.systemClock',      // return time();
            '211 cboxCms.systemClock',      // return Uuid7::lowestAt((int) (microtime(true) * 1000));
        ], $this->reported('SystemClock'));
    }

    public function test_the_message_names_the_read_and_the_contract(): void
    {
        $this->analyse([self::fixture('SystemClock')], [
            [$this->message('time()'), 31],
            [$this->message('microtime()'), 32],
            [$this->message('gettimeofday()'), 33],
            [$this->message('uniqid()'), 34],
            [$this->message('now()'), 35],
            [$this->message('today()'), 36],
            [$this->message('time()'), 37],
            [$this->message('date()'), 38],
            [$this->message('date()'), 44],
            [$this->message('date()'), 45],
            [$this->message('gmdate()'), 46],
            [$this->message('idate()'), 47],
            [$this->message('strftime()'), 48],
            [$this->message('gmstrftime()'), 49],
            [$this->message('getdate()'), 50],
            [$this->message('localtime()'), 51],
            [$this->message('mktime()'), 52],
            [$this->message('gmmktime()'), 53],
            [$this->message('strtotime()'), 69],
            [$this->message('new DateTimeImmutable()'), 70],
            [$this->message('new DateTimeImmutable()'), 71],
            [$this->message('new DateTime()'), 72],
            [$this->message('new DateTimeImmutable()'), 73],
            [$this->message('new DateTimeImmutable()'), 74],
            [$this->message('new DateTimeImmutable()'), 75],
            [$this->message('new DateTimeImmutable()'), 76],
            [$this->message('date_create()'), 77],
            [$this->message('date_create_immutable()'), 78],
            [$this->message('DateTimeImmutable::createFromFormat()'), 91],
            [$this->message('DateTime::createFromFormat()'), 92],
            [$this->message('date_create_immutable_from_format()'), 93],
            [$this->message('date_create_from_format()'), 94],
            [$this->message('Carbon\\Carbon::now()'), 106],
            [$this->message('Carbon\\Carbon::now()'), 107],
            [$this->message('Carbon\\CarbonImmutable::today()'), 108],
            [$this->message('Illuminate\\Support\\Carbon::tomorrow()'), 109],
            [$this->message('Carbon\\Carbon::yesterday()'), 110],
            [$this->message('Carbon\\Carbon::createFromTime()'), 111],
            [$this->message('Carbon\\Carbon::createFromTimeString()'), 112],
            [$this->message('Carbon\\Carbon::createFromDate()'), 113],
            [$this->message('Carbon\\Carbon::create()'), 114],
            [$this->message('Carbon\\Carbon::createMidnightDate()'), 115],
            [$this->message('Carbon\\Carbon::parse()'), 116],
            [$this->message('Carbon\\CarbonImmutable::parse()'), 117],
            [$this->message('new Carbon\\Carbon()'), 118],
            [$this->message('Illuminate\\Support\\Facades\\Date::now()'), 119],
            [$this->message('Illuminate\\Support\\DateFactory::now()'), 120],
            [$this->message('Carbon\\FactoryImmutable::parse()'), 121],
            [$this->message('Carbon\\Carbon::isPast()'), 122],
            [$this->message('Carbon\\Carbon::diffInDays()'), 123],
            [$this->message('Carbon\\Carbon::isToday()'), 124],
            [$this->message('Carbon\\Carbon::isCurrentMonth()'), 125],
            [$this->message('Carbon\\Carbon::diffForHumans()'), 126],
            [$this->message('Carbon\\Carbon::diffInDays()'), 127],
            [$this->message('Carbon\\CarbonImmutable::ago()'), 128],
            [$this->message('$_SERVER[\'REQUEST_TIME\']'), 142],
            [$this->message('$_SERVER[\'REQUEST_TIME_FLOAT\']'), 143],
            [$this->message('Symfony\\Component\\Clock\\now()'), 149],
            [$this->message('Symfony\\Component\\Clock\\NativeClock::now()'), 150],
            [$this->message('Symfony\\Component\\Clock\\MonotonicClock::now()'), 151],
            [$this->message('Symfony\\Component\\Clock\\Clock::get()'), 152],
            [$this->message('Symfony\\Component\\Clock\\Clock::now()'), 153],
            [$this->message('new Symfony\\Component\\Clock\\DatePoint()'), 154],
            [$this->message('the trait Illuminate\\Support\\InteractsWithTime'), 163],
            [$this->message('the trait Symfony\\Component\\Clock\\ClockAwareTrait'), 168],
            [$this->message('time()'), 203],
            [$this->message('microtime()'), 211],
        ]);
    }

    private function message(string $read): string
    {
        return sprintf(
            'Reads the system clock through %s. Ask the Clock contract for the time: only a Clock implementation reads the system clock, so tests control time (GUARDRAILS 2.3). Measure a duration with hrtime(true).',
            $read,
        );
    }

    /**
     * The rule and the three rules that hand it first-class callables, from the test container.
     *
     * @return Rule<Node>
     */
    protected function getRule(): Rule
    {
        $rules = [];

        foreach (self::getContainer()->getServicesByTag('phpstan.rules.rule') as $rule) {
            if ($rule instanceof Rule && ! $rule instanceof UuidCreationRule && str_starts_with($rule::class, 'Cbox\\Cms\\Testkit\\Phpstan\\')) {
                $rules[] = $rule;
            }
        }

        return new RegisteredRules($rules);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/clock-and-uuids.neon'];
    }
}
