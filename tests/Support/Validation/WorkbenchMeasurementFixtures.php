<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Validation;

/**
 * The validator fixtures of the workbench's fixture type app:fixture_measurement (PRD 11.8, 11.12,
 * MILESTONES M1 point 2; gap G3), which has every core field type and every rule for it: input
 * that meets every rule, one broken input per rule per field type with the path and the code the
 * kernel's InputValidator gives it, and the items of its series whose conditional fields read
 * their siblings and the measurement's fields. tests/Feature/Validation/WorkbenchValidatorsTest.php
 * holds the InputValidator to them, and tests/Codecs/EntryFormValidationParityTest.php feeds them
 * through the component kit's EntryForm, the generated TypeScript validator and the InputValidator.
 */
final readonly class WorkbenchMeasurementFixtures
{
    /**
     * Input for fixture_measurement that meets every rule, with every field set.
     *
     * @return array<string, mixed>
     */
    public static function valid(): array
    {
        return [
            'fixture_reading' => '-273.150',
            'fixture_scale' => 'fixture_celsius',
            'fixture_measured_at' => '2026-09-29T12:00:00+02:00',
            'fixture_station' => 'CPH-01',
            'fixture_note' => "Two lines\nof note.",
            'fixture_samples' => 1000,
            'fixture_calibrated' => false,
            'fixture_calibrated_on' => '2000-01-01',
            'fixture_alerts' => ['fixture_low', 'fixture_drift'],
            'fixture_remark' => [
                [
                    '_type' => 'block',
                    '_key' => 'b1',
                    'style' => 'normal',
                    'listItem' => 'bullet',
                    'level' => 1,
                    'children' => [['_type' => 'span', '_key' => 's1', 'text' => 'Checked', 'marks' => ['strong']]],
                ],
            ],
            'fixture_corrects' => '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03',
            'fixture_cited_in' => ['0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01', '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03'],
            'fixture_source' => ['kind' => 'url', 'url' => 'https://example.com/feed/42', 'rel' => ['nofollow']],
            'fixture_sensor' => [
                'fixture_sensor_calibration' => '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a04',
                'fixture_sensor_code' => 'S-7',
                'fixture_sensor_contact' => 'sensors@example.com',
                'fixture_sensor_datasheet' => ['kind' => 'url', 'url' => 'https://example.com/datasheet.pdf'],
                'fixture_sensor_manual' => 'https://example.com/manual',
            ],
            'fixture_series' => [
                ['fixture_series_value' => '-100.00', 'fixture_series_taken_at' => '2000-01-01T00:00:00Z'],
                ['fixture_series_value' => '1000'],
            ],
        ];
    }

    /**
     * Sets the value at a path of dot-separated names and indexes in the input, or removes it.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function withValue(array $input, string $path, mixed $value, bool $remove = false): array
    {
        $segments = explode('.', $path);
        $key = array_shift($segments);
        $key = ctype_digit($key) ? (int) $key : $key;

        if ($segments === []) {
            if ($remove) {
                unset($input[$key]);
            } else {
                $input[$key] = $value;
            }

            return $input;
        }

        $inner = $input[$key] ?? [];
        $input[$key] = self::withValue(is_array($inner) ? $inner : [], implode('.', $segments), $value, $remove);

        return $input;
    }

    /**
     * One broken input per rule per field type, by name: the path of the value in the input as
     * withValue() takes it, the value, the path the InputValidator gives the error at, and its code.
     *
     * @return array<string, array{string, mixed, string, string}>
     */
    public static function brokenRules(): array
    {
        return [
            // decimal: fixture_reading, precision 12 and scale 3
            'decimal: wrong type, a JSON number' => ['fixture_reading', 1.5, 'fixture_reading', 'validation_wrong_type'],
            'decimal: wrong type, not a number' => ['fixture_reading', '1,5', 'fixture_reading', 'validation_wrong_type'],
            'decimal: too many digits after the point' => ['fixture_reading', '1.2345', 'fixture_reading', 'validation_too_many_digits'],
            'decimal: too many digits before the point' => ['fixture_reading', '1234567890', 'fixture_reading', 'validation_too_many_digits'],
            // select, one choice: fixture_scale
            'select: wrong type' => ['fixture_scale', 1, 'fixture_scale', 'validation_wrong_type'],
            'select: not an option' => ['fixture_scale', 'fixture_fahrenheit', 'fixture_scale', 'validation_not_an_option'],
            // datetime: fixture_measured_at
            'datetime: wrong type, a date' => ['fixture_measured_at', '2026-09-29', 'fixture_measured_at', 'validation_wrong_type'],
            'datetime: wrong type, no offset' => ['fixture_measured_at', '2026-09-29T12:00:00', 'fixture_measured_at', 'validation_wrong_type'],
            // text: fixture_station, 3 to 40 characters
            'text: wrong type' => ['fixture_station', 40, 'fixture_station', 'validation_wrong_type'],
            'text: too short' => ['fixture_station', 'CP', 'fixture_station', 'validation_too_short'],
            'text: too long' => ['fixture_station', str_repeat('x', 41), 'fixture_station', 'validation_too_long'],
            // long text: fixture_note, at most 10,000 characters
            'long text: wrong type' => ['fixture_note', ['a'], 'fixture_note', 'validation_wrong_type'],
            'long text: too long' => ['fixture_note', str_repeat('x', 10001), 'fixture_note', 'validation_too_long'],
            // integer: fixture_samples, 1 to 1000
            'integer: wrong type, a string' => ['fixture_samples', '5', 'fixture_samples', 'validation_wrong_type'],
            'integer: wrong type, a fraction' => ['fixture_samples', 5.0, 'fixture_samples', 'validation_wrong_type'],
            'integer: below the minimum' => ['fixture_samples', 0, 'fixture_samples', 'validation_below_minimum'],
            'integer: above the maximum' => ['fixture_samples', 1001, 'fixture_samples', 'validation_above_maximum'],
            // boolean: fixture_calibrated
            'boolean: wrong type' => ['fixture_calibrated', 'yes', 'fixture_calibrated', 'validation_wrong_type'],
            // date: fixture_calibrated_on, 2000-01-01 to 2099-12-31
            'date: wrong type, no real day' => ['fixture_calibrated_on', '2026-02-30', 'fixture_calibrated_on', 'validation_wrong_type'],
            'date: below the minimum' => ['fixture_calibrated_on', '1999-12-31', 'fixture_calibrated_on', 'validation_below_minimum'],
            'date: above the maximum' => ['fixture_calibrated_on', '2100-01-01', 'fixture_calibrated_on', 'validation_above_maximum'],
            // select, several choices: fixture_alerts, 1 to 2 of them
            'multiple select: wrong type' => ['fixture_alerts', 'fixture_low', 'fixture_alerts', 'validation_wrong_type'],
            'multiple select: an item of the wrong type' => ['fixture_alerts', [1], 'fixture_alerts[0]', 'validation_wrong_type'],
            'multiple select: not an option' => ['fixture_alerts', ['fixture_low', 'fixture_storm'], 'fixture_alerts[1]', 'validation_not_an_option'],
            'multiple select: an option twice' => ['fixture_alerts', ['fixture_high', 'fixture_high'], 'fixture_alerts[1]', 'validation_duplicate_item'],
            'multiple select: too few items' => ['fixture_alerts', [], 'fixture_alerts', 'validation_too_few_items'],
            'multiple select: too many items' => ['fixture_alerts', ['fixture_low', 'fixture_high', 'fixture_drift'], 'fixture_alerts', 'validation_too_many_items'],
            // rich text: fixture_remark, the style normal, the mark strong, bullet lists and no links
            'rich text: wrong type' => ['fixture_remark', 'Checked', 'fixture_remark', 'validation_wrong_type'],
            'rich text: invalid structure' => ['fixture_remark.0._type', 'image', 'fixture_remark[0]._type', 'validation_invalid_rich_text'],
            'rich text: a style it does not allow' => ['fixture_remark.0.style', 'h2', 'fixture_remark[0].style', 'validation_rich_text_not_allowed'],
            'rich text: a mark it does not allow' => ['fixture_remark.0.children.0.marks', ['em'], 'fixture_remark[0].children[0].marks[0]', 'validation_rich_text_not_allowed'],
            'rich text: a list it does not allow' => ['fixture_remark.0.listItem', 'number', 'fixture_remark[0].listItem', 'validation_rich_text_not_allowed'],
            'rich text: a link it does not allow' => ['fixture_remark.0.markDefs', [['_type' => 'link', '_key' => 'l1', 'href' => 'https://example.com/']], 'fixture_remark[0].markDefs[0]._type', 'validation_rich_text_not_allowed'],
            // relation to one entry: fixture_corrects, an entry's id
            'relation: wrong type' => ['fixture_corrects', 7, 'fixture_corrects', 'validation_wrong_type'],
            'relation: not an entry id' => ['fixture_corrects', 'DK-042', 'fixture_corrects', 'validation_wrong_type'],
            'relation: a UUID of another version' => ['fixture_corrects', '0199a3c1-2b4d-4e5f-8a6b-1c2d3e4f5a03', 'fixture_corrects', 'validation_wrong_type'],
            'relation: a list where one entry belongs' => ['fixture_corrects', ['0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03'], 'fixture_corrects', 'validation_wrong_type'],
            // relation to several entries: fixture_cited_in, at most 200, each once
            'multiple relation: wrong type' => ['fixture_cited_in', '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03', 'fixture_cited_in', 'validation_wrong_type'],
            'multiple relation: an item that is no entry id' => ['fixture_cited_in.1', 'x', 'fixture_cited_in[1]', 'validation_wrong_type'],
            'multiple relation: an entry twice, in another case' => ['fixture_cited_in.1', '0199A3C1-2B4D-7E5F-8A6B-1C2D3E4F5A01', 'fixture_cited_in[1]', 'validation_duplicate_item'],
            'multiple relation: too many items' => ['fixture_cited_in', array_map(static fn (int $item): string => sprintf('0199a3c1-2b4d-7e5f-8a6b-%012x', $item), range(1, 201)), 'fixture_cited_in', 'validation_too_many_items'],
            // link: fixture_source, an entry, a node or a URL
            'link: wrong type' => ['fixture_source', 'https://example.com/feed/42', 'fixture_source', 'validation_wrong_type'],
            'link: no kind' => ['fixture_source.kind', null, 'fixture_source.kind', 'validation_required'],
            'link: a kind that is none' => ['fixture_source.kind', 'page', 'fixture_source.kind', 'validation_not_an_option'],
            'link: a member of another kind' => ['fixture_source.entry', '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03', 'fixture_source', 'validation_unknown_field'],
            'link: a URL of another scheme' => ['fixture_source.url', 'ftp://example.com/feed', 'fixture_source.url', 'validation_invalid_format'],
            'link: a URL over the length limit' => ['fixture_source.url', 'https://example.com/'.str_repeat('a', 2029), 'fixture_source.url', 'validation_too_long'],
            'link: a rel that is none' => ['fixture_source.rel.0', 'external', 'fixture_source.rel[0]', 'validation_not_an_option'],
            'link: a rel twice' => ['fixture_source.rel', ['ugc', 'ugc'], 'fixture_source.rel[1]', 'validation_duplicate_item'],
            'link: an entry without the entry' => ['fixture_source', ['kind' => 'entry'], 'fixture_source.entry', 'validation_required'],
            'link: an entry that is no entry id' => ['fixture_source', ['kind' => 'entry', 'entry' => 'DK-042'], 'fixture_source.entry', 'validation_wrong_type'],
            'link: an entry in a locale that is none' => ['fixture_source', ['kind' => 'entry', 'entry' => '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03', 'locale' => 'danish'], 'fixture_source.locale', 'validation_invalid_format'],
            'link: a node that is no node id' => ['fixture_source', ['kind' => 'node', 'node' => 7], 'fixture_source.node', 'validation_wrong_type'],
            'group: a nested link of a kind the field does not allow' => ['fixture_sensor.fixture_sensor_datasheet', ['kind' => 'node', 'node' => '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03'], 'fixture_sensor.fixture_sensor_datasheet.kind', 'validation_not_an_option'],
            'group: a nested relation that is no entry id' => ['fixture_sensor.fixture_sensor_calibration', 'S-7', 'fixture_sensor.fixture_sensor_calibration', 'validation_wrong_type'],
            // group once: fixture_sensor
            'group: wrong type' => ['fixture_sensor', ['S-7'], 'fixture_sensor', 'validation_wrong_type'],
            'group: a nested field it does not have' => ['fixture_sensor.fixture_sensor_colour', 'grey', 'fixture_sensor.fixture_sensor_colour', 'validation_unknown_field'],
            'group: a required nested field left out' => ['fixture_sensor.fixture_sensor_code', null, 'fixture_sensor.fixture_sensor_code', 'validation_required'],
            'group: a nested text not an email address' => ['fixture_sensor.fixture_sensor_contact', 'sensors.example.com', 'fixture_sensor.fixture_sensor_contact', 'validation_invalid_format'],
            'group: a nested text not a URL' => ['fixture_sensor.fixture_sensor_manual', 'ftp://example.com/manual', 'fixture_sensor.fixture_sensor_manual', 'validation_invalid_format'],
            // repeated group: fixture_series, 1 to 3 items
            'repeated group: wrong type' => ['fixture_series', ['fixture_series_value' => '1'], 'fixture_series', 'validation_wrong_type'],
            'repeated group: an item of the wrong type' => ['fixture_series.1', '1', 'fixture_series[1]', 'validation_wrong_type'],
            'repeated group: too few items' => ['fixture_series', [], 'fixture_series', 'validation_too_few_items'],
            'repeated group: too many items' => ['fixture_series', array_fill(0, 4, ['fixture_series_value' => '1']), 'fixture_series', 'validation_too_many_items'],
            'repeated group: a required nested field left out' => ['fixture_series.1.fixture_series_value', null, 'fixture_series[1].fixture_series_value', 'validation_required'],
            'repeated group: a nested decimal below the minimum' => ['fixture_series.0.fixture_series_value', '-100.01', 'fixture_series[0].fixture_series_value', 'validation_below_minimum'],
            'repeated group: a nested decimal above the maximum' => ['fixture_series.0.fixture_series_value', '1000.01', 'fixture_series[0].fixture_series_value', 'validation_above_maximum'],
            'repeated group: a nested datetime below the minimum' => ['fixture_series.0.fixture_series_taken_at', '2000-01-01T00:59:59+01:00', 'fixture_series[0].fixture_series_taken_at', 'validation_below_minimum'],
            'repeated group: a nested datetime above the maximum' => ['fixture_series.0.fixture_series_taken_at', '2100-01-01T00:00:00Z', 'fixture_series[0].fixture_series_taken_at', 'validation_above_maximum'],
            'repeated group: a nested field it does not have' => ['fixture_series.0.fixture_series_unit', 'K', 'fixture_series[0].fixture_series_unit', 'validation_unknown_field'],
            // the type
            'a field the type does not have' => ['fixture_humidity', 40, 'fixture_humidity', 'validation_unknown_field'],
            'an extension namespace the type does not have' => ['ext.acme', ['fixture_humidity' => 40], 'ext.acme', 'validation_unknown_field'],
        ];
    }

    /**
     * The items of the series with their conditional fields, by name: the fields of the one item
     * beside its value, the measurement's fields, and the errors the InputValidator gives as
     * `path: code`.
     *
     * @return array<string, array{array<string, mixed>, array<string, mixed>, list<string>}>
     */
    public static function conditionalItems(): array
    {
        return [
            'hidden: no method, so no operator is asked for' => [[], [], []],
            'shown and required: a manual reading without its operator' => [['fixture_series_method' => 'fixture_manual'], [], ['fixture_series[0].fixture_series_operator: validation_required']],
            'shown: a manual reading with its operator' => [['fixture_series_method' => 'fixture_manual', 'fixture_series_operator' => 'Ana'], [], []],
            'hidden with a value under clear' => [['fixture_series_method' => 'fixture_logged', 'fixture_series_operator' => 'Ana'], [], ['fixture_series[0].fixture_series_operator: validation_hidden_field']],
            'hidden with a value under keep, checked as any value' => [['fixture_series_device' => str_repeat('d', 61)], [], ['fixture_series[0].fixture_series_device: validation_too_long']],
            'any: shown by the measurement\'s field one group up' => [['fixture_series_device' => 'D-1'], ['fixture_calibrated' => true], []],
            'all: shown and required on the kelvin scale with a method' => [['fixture_series_method' => 'fixture_logged'], ['fixture_scale' => 'fixture_kelvin'], ['fixture_series[0].fixture_series_check: validation_required']],
            'all: hidden with a value under clear on the celsius scale' => [['fixture_series_method' => 'fixture_logged', 'fixture_series_check' => 'x'], [], ['fixture_series[0].fixture_series_check: validation_hidden_field']],
        ];
    }
}
