<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use DateTimeImmutable;

/**
 * Fields of the workbench's fixture types for the tests of the entry commands: an article with a
 * title, a flag, a number, a day, two topics and a source, and a measurement with a reading, its
 * scale, the time it was taken, a station, alerts, a sensor and a series.
 */
final class EntryFields
{
    /**
     * @param  array<string, FieldValue>  $more  further fields, such as the encrypted embargo
     */
    public static function article(string $title = 'A first title', bool $featured = true, int $minutes = 4, array $more = []): FieldValues
    {
        return self::of([
            ...$more,
            'fixture_title' => new TextValue($title),
            'fixture_featured' => new BooleanValue($featured),
            'fixture_reading_minutes' => new IntegerValue($minutes),
            'fixture_published_on' => new DateValue('2026-03-09'),
            'fixture_topics' => new ListValue(new TextValue('fixture_science'), new TextValue('fixture_culture')),
            'fixture_sources' => new ListValue(new GroupValue(self::map([
                'fixture_source_title' => new TextValue('A "quoted" source'),
                'fixture_source_url' => new TextValue('https://example.org/source'),
            ]))),
        ]);
    }

    public static function measurement(string $reading = '21.125', string $station = 'north-1'): FieldValues
    {
        return self::of([
            'fixture_reading' => new DecimalValue($reading),
            'fixture_scale' => new TextValue('fixture_celsius'),
            'fixture_measured_at' => new DateTimeValue(new DateTimeImmutable('2026-03-10T11:59:58.250000+00:00')),
            'fixture_station' => new TextValue($station),
            'fixture_samples' => new IntegerValue(3),
            'fixture_calibrated' => new BooleanValue(false),
            'fixture_alerts' => new ListValue(new TextValue('fixture_high')),
            'fixture_sensor' => new GroupValue(self::map(['fixture_sensor_code' => new TextValue('S-7')])),
            'fixture_series' => new ListValue(
                new GroupValue(self::map(['fixture_series_value' => new DecimalValue('21.10')])),
                new GroupValue(self::map(['fixture_series_value' => new DecimalValue('21.15')])),
            ),
        ]);
    }

    /**
     * The owner's fields.
     *
     * @param  array<string, FieldValue>  $fields
     */
    public static function of(array $fields): FieldValues
    {
        return new FieldValues(self::map($fields));
    }

    /**
     * @param  array<string, FieldValue>  $fields
     */
    public static function map(array $fields): FieldMap
    {
        $named = [];

        foreach ($fields as $handle => $value) {
            $named[] = new NamedValue(new FieldHandle($handle), $value);
        }

        return new FieldMap(...$named);
    }
}
