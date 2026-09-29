<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ReceiptCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Tests\Support\TypeScript\TypeScriptValidators;
use LogicException;
use stdClass;
use Workbench\App\Cms\Generated\Boundary\AppFixtureArticleCodecV1;
use Workbench\App\Cms\Generated\Boundary\AppFixtureMeasurementCodecV1;

/*
 * The TypeScript validators cms:generate writes, run in Node against what the PHP codecs actually
 * write (PRD 11.12, GUARDRAILS 2.2 and 9): one test per contract version, the workbench's records
 * of both fixture types, the receipt, the problem details and the envelope. Each test has its own
 * fixtures, written by hand: documents with every field type, null and omitted fields that both
 * sides must accept, and documents that break one rule each, which both must refuse at the same
 * value. Every document the PHP codec accepts is written again by the PHP codec, as callers with
 * every classification access see it, and the TypeScript validator must accept each of those
 * outputs. A value of the PHP output planted wrong makes the validator refuse it.
 *
 * Node runs the modules of workbench/resources/js/cms/generated, compiled by the TypeScript the JS
 * toolchain pins: the same modules a browser loads, until the panel (B1) loads them in one.
 */

const GENERATED = 'workbench/resources/js/cms/generated';

const VALIDATED_ARTICLE_ID = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01';

const VALIDATED_MEASUREMENT_ID = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02';

/**
 * The path at which a PHP codec refuses a document read with every classification access, '' for
 * the document itself, or null when it accepts it.
 *
 * @template TDto of object
 *
 * @param  JsonCodec<TDto>  $codec
 */
function phpRefusedAt(JsonCodec $codec, string $document): ?string
{
    try {
        $codec->decode($document, ClassificationAccess::Sensitive);
    } catch (DecodingFailed $failure) {
        return $failure->path?->toString() ?? '';
    }

    return null;
}

/**
 * What the PHP codec writes for a document it accepts, as a caller with each classification access
 * sees it.
 *
 * @template TDto of object
 *
 * @param  JsonCodec<TDto>  $codec
 * @return list<string>
 */
function phpOutputs(JsonCodec $codec, string $document): array
{
    $dto = $codec->decode($document, ClassificationAccess::Sensitive);

    return array_map(static fn (ClassificationAccess $access): string => $codec->encode($dto, $access), ClassificationAccess::cases());
}

/**
 * Checks each fixture against its expected verdict on both sides, and every PHP output of an
 * accepted fixture against the TypeScript validator.
 *
 * @template TDto of object
 *
 * @param  JsonCodec<TDto>  $codec
 * @param  array<string, array{string, ?string}>  $fixtures  name to the document and the path it is refused at, or null
 */
function crossCheck(JsonCodec $codec, string $module, string $validator, array $fixtures): void
{
    $cases = [];
    $expected = [];

    foreach ($fixtures as $name => [$document, $path]) {
        expect(phpRefusedAt($codec, $document))->toBe($path, 'The PHP codec on the fixture '.$name);
        $cases[] = ['module' => $module, 'validator' => $validator, 'document' => $document];
        $expected[] = [$name, $path];

        if ($path === null) {
            foreach (phpOutputs($codec, $document) as $index => $output) {
                $cases[] = ['module' => $module, 'validator' => $validator, 'document' => $output];
                $expected[] = [$name.', written by PHP for '.ClassificationAccess::cases()[$index]->value, null];
            }
        }
    }

    foreach (TypeScriptValidators::run(GENERATED, $cases) as $index => $verdict) {
        [$name, $path] = $expected[$index];
        $actual = $verdict['valid'] ? null : ($verdict['path'] ?? '');

        expect($actual)->toBe($path, sprintf('The TypeScript validator on %s: %s', $name, $verdict['reason'] ?? 'valid'));
    }
}

/**
 * A document with one value planted wrong: the value at the path of keys and indexes replaced.
 *
 * @param  array<mixed>  $path  the keys and indexes, from the document to the value
 */
function planted(string $document, array $path, mixed $value): string
{
    return json_encode(plantedValue(json_decode($document, false, 64, JSON_THROW_ON_ERROR), array_values($path), $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * @param  list<mixed>  $path
 */
function plantedValue(mixed $node, array $path, mixed $value): mixed
{
    if ($path === []) {
        return $value;
    }

    $segment = array_shift($path);

    if (is_int($segment) && is_array($node)) {
        $node[$segment] = plantedValue($node[$segment] ?? null, $path, $value);

        return $node;
    }

    if (is_string($segment) && $node instanceof stdClass) {
        $node->{$segment} = plantedValue($node->{$segment} ?? null, $path, $value);

        return $node;
    }

    throw new LogicException(sprintf('The document has no place for the segment of type %s to plant a value at.', get_debug_type($segment)));
}

/**
 * @param  array<string, mixed>  $fields
 */
function measurementJson(array $fields = []): string
{
    return json_encode([
        'cms_id' => VALIDATED_MEASUREMENT_ID,
        'fixture_measured_at' => '2026-03-10T12:59:59.25+01:00',
        'fixture_reading' => '-12.5',
        'fixture_scale' => 'fixture_celsius',
        ...$fields,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * The measurement with every field set.
 */
function fullMeasurementJson(): string
{
    return measurementJson([
        'fixture_alerts' => ['fixture_low', 'fixture_drift'],
        'fixture_calibrated' => true,
        'fixture_calibrated_on' => '2026-02-28',
        'fixture_note' => 'Sensor cleaned first. Ærø ✓',
        'fixture_remark' => [[
            '_type' => 'block',
            '_key' => 'a',
            'style' => 'normal',
            'listItem' => 'bullet',
            'level' => 1,
            'children' => [['_type' => 'span', '_key' => 'a1', 'text' => 'Drift ', 'marks' => ['strong']], ['_type' => 'span', '_key' => 'a2', 'text' => 'seen']],
        ]],
        'fixture_samples' => 1000,
        'fixture_sensor' => ['fixture_sensor_code' => 'S-1', 'fixture_sensor_contact' => 'care@example.org', 'fixture_sensor_manual' => 'https://example.org/manual?page=2'],
        'fixture_series' => [
            ['fixture_series_value' => '-100.00', 'fixture_series_taken_at' => '2026-03-10T11:00:00Z'],
            ['fixture_series_value' => '1000'],
        ],
        'fixture_station' => 'DK-042',
    ]);
}

/**
 * @param  array<string, mixed>  $fields
 */
function articleJson(array $fields = []): string
{
    return json_encode(['cms_id' => VALIDATED_ARTICLE_ID, 'fixture_featured' => false, ...$fields], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * The article with every field set.
 */
function fullArticleJson(): string
{
    return articleJson([
        'fixture_body' => [[
            '_type' => 'block',
            '_key' => 'a',
            'style' => 'h2',
            'markDefs' => [['_type' => 'link', '_key' => 'l', 'href' => 'https://example.org/report']],
            'children' => [['_type' => 'span', '_key' => 'a1', 'text' => 'Read the report', 'marks' => ['strong', 'l'], 'custom' => ['kept' => 1]]],
        ]],
        'fixture_embargo' => ['fixture_embargo_until' => '2026-03-10T09:00:00+01:00', 'fixture_embargo_reason' => 'Waiting for the minister.'],
        'fixture_featured' => true,
        'fixture_published_on' => '2026-03-09',
        'fixture_reading_minutes' => 7,
        'fixture_sources' => [['fixture_source_title' => 'The report', 'fixture_source_url' => 'https://example.org/report'], ['fixture_source_title' => 'An interview']],
        'fixture_title' => 'Budget: the report is out',
        'fixture_topics' => ['fixture_politics', 'fixture_science'],
    ]);
}

const COMMITTED_RECEIPT_JSON = '{"changeset_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","consistency_token":{"generation":2,"lsn":"16/B374D848"},"outcome":"committed","position":"4827","projections":[{"acknowledged_at":"2026-03-10T12:00:00.250000Z","projection":"fragments","state":"acknowledged"},{"acknowledged_at":null,"projection":"acme.search","state":"pending"}],"retention_class":"standard","wait_level":"origin"}';

const AGENT_ENVELOPE_JSON = '{"correlation_id":"trace-7f3a","dry_run":true,"idempotency_key":"order-1042","on_behalf_of":["0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a10","0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a11"],"provenance":{"model":{"name":"writer","version":"2026-03"},"parameters":[{"name":"max_tokens","value":"800"},{"name":"temperature","value":"0.2"}],"prompt":"prompts/summary/v3","sources":["https://example.test/feed/42","feed:item:9"]},"wait_level":"edge"}';

/**
 * A problem details document of a code, with the catalog's title, type, status and retryable.
 *
 * @param  array<string, mixed>  $fields
 */
function problemJson(ErrorCode $code = ErrorCode::ValidationFailed, array $fields = []): string
{
    $entry = $code->entry();

    return json_encode([
        'code' => $code->value,
        'detail' => 'Two fields of the command break their rules.',
        'errors' => [
            ['code' => 'validation_failed', 'detail' => 'The text is too long.', 'field' => 'fields.blocks[2].text'],
            ['code' => 'version_conflict', 'detail' => 'The entry changed after it was read.', 'field' => null],
        ],
        'instance' => '/v1/entries/'.VALIDATED_ARTICLE_ID,
        'retryable' => $entry->retryable,
        'status' => $entry->http->value,
        'title' => $entry->explanation,
        'type' => $entry->docs(),
        ...$fields,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

it('accepts every record of app:fixture_measurement v1 the PHP codec writes, and refuses what it refuses at the same value', function (): void {
    $block = static fn (array $block): array => ['fixture_remark' => [['_type' => 'block', '_key' => 'a', 'children' => [['_type' => 'span', '_key' => 'a1', 'text' => 'x']], ...$block]]];

    crossCheck(new AppFixtureMeasurementCodecV1, 'records/AppFixtureMeasurementV1', 'validateAppFixtureMeasurementV1', [
        'every optional field omitted' => [measurementJson(), null],
        'every field set' => [fullMeasurementJson(), null],
        'every optional field null' => [measurementJson(array_fill_keys(['fixture_alerts', 'fixture_calibrated', 'fixture_calibrated_on', 'fixture_note', 'fixture_remark', 'fixture_samples', 'fixture_sensor', 'fixture_series', 'fixture_station'], null)), null],
        'a decimal with leading zeros and the most digits' => [measurementJson(['fixture_reading' => '-000999999999.999']), null],
        'a date-time at the largest offset and six decimals' => [measurementJson(['fixture_measured_at' => '2026-03-10T23:59:59.999999+23:59']), null],
        'a leap day' => [measurementJson(['fixture_calibrated_on' => '2028-02-29']), null],
        'a quoted email address' => [measurementJson(['fixture_sensor' => ['fixture_sensor_code' => 'S', 'fixture_sensor_contact' => '"care..team"@example.org']]), null],
        'a quoted email address with a space' => [measurementJson(['fixture_sensor' => ['fixture_sensor_code' => 'S', 'fixture_sensor_contact' => '"care team"@example.org']]), 'fixture_sensor.fixture_sensor_contact'],
        'an unknown key' => [measurementJson(['fixture_unknown' => 1]), ''],
        'the id of another UUID version' => [measurementJson(['cms_id' => '0199a3c1-2b4d-4e5f-8a6b-1c2d3e4f5a02']), 'cms_id'],
        'a required field missing' => ['{"cms_id":"'.VALIDATED_MEASUREMENT_ID.'","fixture_measured_at":"2026-03-10T12:00:00Z","fixture_scale":"fixture_kelvin"}', 'fixture_reading'],
        'a required field null' => [measurementJson(['fixture_scale' => null]), 'fixture_scale'],
        'a decimal as a JSON number' => [measurementJson(['fixture_reading' => -12.5]), 'fixture_reading'],
        'a decimal beyond its scale' => [measurementJson(['fixture_reading' => '1.2345']), 'fixture_reading'],
        'a decimal beyond its precision' => [measurementJson(['fixture_reading' => '1000000000']), 'fixture_reading'],
        'a decimal in exponent form' => [measurementJson(['fixture_reading' => '1e3']), 'fixture_reading'],
        'a date-time without an offset' => [measurementJson(['fixture_measured_at' => '2026-03-10T12:00:00']), 'fixture_measured_at'],
        'a date-time at -00:00' => [measurementJson(['fixture_measured_at' => '2026-03-10T12:00:00-00:00']), 'fixture_measured_at'],
        'a date-time with seven decimals' => [measurementJson(['fixture_measured_at' => '2026-03-10T12:00:00.1234567Z']), 'fixture_measured_at'],
        'a date-time at the 25th hour' => [measurementJson(['fixture_measured_at' => '2026-03-10T24:00:00Z']), 'fixture_measured_at'],
        'a date that is no day' => [measurementJson(['fixture_calibrated_on' => '2026-02-29']), 'fixture_calibrated_on'],
        'a date in year zero' => [measurementJson(['fixture_calibrated_on' => '0000-01-01']), 'fixture_calibrated_on'],
        'a date before its minimum' => [measurementJson(['fixture_calibrated_on' => '1999-12-31']), 'fixture_calibrated_on'],
        'an integer with a fraction' => [measurementJson(['fixture_samples' => 1.5]), 'fixture_samples'],
        'an integer in a string' => [measurementJson(['fixture_samples' => '5']), 'fixture_samples'],
        'an integer above its maximum' => [measurementJson(['fixture_samples' => 1001]), 'fixture_samples'],
        'a boolean in a string' => [measurementJson(['fixture_calibrated' => 'true']), 'fixture_calibrated'],
        'a value that is not an option' => [measurementJson(['fixture_scale' => 'fixture_fahrenheit']), 'fixture_scale'],
        'too many items' => [measurementJson(['fixture_alerts' => ['fixture_low', 'fixture_high', 'fixture_drift']]), 'fixture_alerts'],
        'an item twice' => [measurementJson(['fixture_alerts' => ['fixture_low', 'fixture_low']]), 'fixture_alerts[1]'],
        'no items' => [measurementJson(['fixture_alerts' => []]), 'fixture_alerts'],
        'text shorter than its minimum' => [measurementJson(['fixture_station' => 'DK']), 'fixture_station'],
        'a list where text belongs' => [measurementJson(['fixture_note' => ['x']]), 'fixture_note'],
        'an email address without a domain' => [measurementJson(['fixture_sensor' => ['fixture_sensor_code' => 'S', 'fixture_sensor_contact' => 'care@localhost']]), 'fixture_sensor.fixture_sensor_contact'],
        'a URL that is not http' => [measurementJson(['fixture_sensor' => ['fixture_sensor_code' => 'S', 'fixture_sensor_manual' => 'ftp://example.org/manual']]), 'fixture_sensor.fixture_sensor_manual'],
        'a URL with a space' => [measurementJson(['fixture_sensor' => ['fixture_sensor_code' => 'S', 'fixture_sensor_manual' => 'https://example.org/a manual']]), 'fixture_sensor.fixture_sensor_manual'],
        'a group that is a list' => [measurementJson(['fixture_sensor' => [['fixture_sensor_code' => 'S']]]), 'fixture_sensor'],
        'a group with an unknown key' => [measurementJson(['fixture_sensor' => ['fixture_sensor_code' => 'S', 'fixture_other' => 'x']]), 'fixture_sensor'],
        'a repeated group item without its required field' => [measurementJson(['fixture_series' => [['fixture_series_value' => '1'], ['fixture_series_taken_at' => '2026-03-10T11:00:00Z']]]), 'fixture_series[1].fixture_series_value'],
        'a repeated group item below its minimum' => [measurementJson(['fixture_series' => [['fixture_series_value' => '-100.01']]]), 'fixture_series[0].fixture_series_value'],
        'a date-time after its maximum' => [measurementJson(['fixture_series' => [['fixture_series_value' => '1', 'fixture_series_taken_at' => '2100-01-01T01:00:00.000001+01:00']]]), 'fixture_series[0].fixture_series_taken_at'],
        'rich text that is an object' => [measurementJson(['fixture_remark' => ['_type' => 'block']]), 'fixture_remark'],
        'rich text with a number that is not an integer' => [measurementJson($block(['custom' => 1.5])), 'fixture_remark[0].custom'],
        'rich text with a style it does not allow' => [measurementJson($block(['style' => 'h2'])), 'fixture_remark[0].style'],
        'rich text with a list kind it does not allow' => [measurementJson($block(['listItem' => 'number'])), 'fixture_remark[0].listItem'],
        'rich text with a level without a list' => [measurementJson($block(['level' => 2])), 'fixture_remark[0].level'],
        'rich text with a link it does not allow' => [measurementJson($block(['markDefs' => [['_type' => 'link', '_key' => 'l', 'href' => 'https://example.org']]])), 'fixture_remark[0].markDefs[0]._type'],
        'rich text with a decorator it does not allow' => [measurementJson($block(['children' => [['_type' => 'span', '_key' => 'a1', 'text' => 'x', 'marks' => ['em']]]])), 'fixture_remark[0].children[0].marks[0]'],
        'rich text with a block without spans' => [measurementJson($block(['children' => []])), 'fixture_remark[0].children'],
        'rich text with a span key twice' => [measurementJson($block(['children' => [['_type' => 'span', '_key' => 'a1', 'text' => 'x'], ['_type' => 'span', '_key' => 'a1', 'text' => 'y']]])), 'fixture_remark[0].children[1]._key'],
        'rich text with a span without text' => [measurementJson($block(['children' => [['_type' => 'span', '_key' => 'a1']]])), 'fixture_remark[0].children[0].text'],
        'rich text with an object that is not a block' => [measurementJson(['fixture_remark' => [['_type' => 'image', '_key' => 'a']]]), 'fixture_remark[0]._type'],
    ]);
});

it('accepts every record of app:fixture_article v1 the PHP codec writes, and refuses what it refuses at the same value', function (): void {
    crossCheck(new AppFixtureArticleCodecV1, 'records/AppFixtureArticleV1', 'validateAppFixtureArticleV1', [
        'every optional field omitted' => [articleJson(), null],
        'every field set' => [fullArticleJson(), null],
        'a group and a repeated group null' => [articleJson(['fixture_embargo' => null, 'fixture_sources' => null]), null],
        'the required field of a group missing' => [articleJson(['fixture_embargo' => ['fixture_embargo_reason' => 'x']]), 'fixture_embargo.fixture_embargo_until'],
        'too many topics' => [articleJson(['fixture_topics' => ['fixture_politics', 'fixture_science', 'fixture_culture']]), 'fixture_topics'],
        'a topic twice' => [articleJson(['fixture_topics' => ['fixture_science', 'fixture_science']]), 'fixture_topics[1]'],
        'a title longer than 255 characters' => [articleJson(['fixture_title' => str_repeat('æ', 256)]), 'fixture_title'],
        'a title of 255 characters of four bytes each' => [articleJson(['fixture_title' => str_repeat('😀', 255)]), null],
        'a mark that is neither a decorator nor a definition' => [articleJson(['fixture_body' => [['_type' => 'block', '_key' => 'a', 'children' => [['_type' => 'span', '_key' => 'a1', 'text' => 'x', 'marks' => ['l']]]]]]), 'fixture_body[0].children[0].marks[0]'],
        'a link to a URL that is not http' => [articleJson(['fixture_body' => [['_type' => 'block', '_key' => 'a', 'markDefs' => [['_type' => 'link', '_key' => 'l', 'href' => 'javascript:alert(1)']], 'children' => [['_type' => 'span', '_key' => 'a1', 'text' => 'x']]]]]), 'fixture_body[0].markDefs[0].href'],
        'a block key twice' => [articleJson(['fixture_body' => [['_type' => 'block', '_key' => 'a', 'children' => [['_type' => 'span', '_key' => 'a1', 'text' => 'x']]], ['_type' => 'block', '_key' => 'a', 'children' => [['_type' => 'span', '_key' => 'a1', 'text' => 'y']]]]]), 'fixture_body[1]._key'],
        'reading minutes of zero' => [articleJson(['fixture_reading_minutes' => 0]), 'fixture_reading_minutes'],
        'a published day before 2000' => [articleJson(['fixture_published_on' => '1999-12-31']), 'fixture_published_on'],
        'a featured flag of null' => [articleJson(['fixture_featured' => null]), 'fixture_featured'],
        'a source that is not an object' => [articleJson(['fixture_sources' => ['The report']]), 'fixture_sources[0]'],
        'eleven sources' => [articleJson(['fixture_sources' => array_fill(0, 11, ['fixture_source_title' => 'x'])]), 'fixture_sources'],
    ]);
});

it('accepts every receipt v1 the PHP codec writes, and refuses what it refuses at the same value', function (): void {
    $receipt = static fn (string $from, string $to): string => str_replace($from, $to, COMMITTED_RECEIPT_JSON);

    crossCheck(new ReceiptCodecV1, 'protocol/ReceiptV1', 'validateReceiptV1', [
        'a committed receipt' => [COMMITTED_RECEIPT_JSON, null],
        'a rejected receipt' => ['{"changeset_id":null,"consistency_token":null,"outcome":"rejected","position":null,"projections":[],"retention_class":"standard","wait_level":"commit"}', null],
        'a wait timeout without a consistency token' => ['{"changeset_id":"0199A3C1-2B4D-7E5F-8A6B-1C2D3E4F5A02","consistency_token":null,"outcome":"committed_wait_timeout","position":"3","projections":[],"retention_class":"evidence","wait_level":"verified"}', null],
        'the largest consistency token' => [$receipt('"generation":2,"lsn":"16/B374D848"', '"generation":4294967295,"lsn":"FFFFFFFF/FFFFFFFF"'), null],
        'the largest position' => [$receipt('"4827"', '"18446744073709551615"'), null],
        'an unknown key' => [$receipt('"outcome"', '"extra":1,"outcome"'), ''],
        'an outcome that is not one' => [$receipt('"outcome":"committed"', '"outcome":"done"'), 'outcome'],
        'the consistency token missing' => [$receipt('"consistency_token":{"generation":2,"lsn":"16/B374D848"},', ''), 'consistency_token'],
        'a generation of zero' => [$receipt('"generation":2', '"generation":0'), 'consistency_token.generation'],
        'a generation above 32 bits' => [$receipt('"generation":2', '"generation":4294967296'), 'consistency_token.generation'],
        'a WAL position in lower case' => [$receipt('16/B374D848', '16/b374d848'), 'consistency_token.lsn'],
        'the position missing' => [$receipt('"position":"4827",', ''), 'position'],
        'a position with a leading zero' => [$receipt('"4827"', '"04827"'), 'position'],
        'a position as a number' => [$receipt('"4827"', '4827'), 'position'],
        'a projection name with an upper case letter' => [$receipt('"fragments"', '"Fragments"'), 'projections[0].projection'],
        'a state that is not one' => [$receipt('"state":"pending"', '"state":"late"'), 'projections[1].state'],
        'an acknowledgement time that is no time' => [$receipt('2026-03-10T12:00:00.250000Z', 'yesterday'), 'projections[0].acknowledged_at'],
        'a changeset id of another UUID version' => [$receipt('0199a3c1-2b4d-7e5f', '0199a3c1-2b4d-1e5f'), 'changeset_id'],
        'a wait level that is not one' => [$receipt('"wait_level":"origin"', '"wait_level":"soon"'), 'wait_level'],
    ]);
});

it('accepts every problem details document v1 the PHP codec writes, and refuses what it refuses at the same value', function (): void {
    crossCheck(new ProblemCodecV1, 'protocol/ProblemV1', 'validateProblemV1', [
        'a problem with field errors' => [problemJson(), null],
        'a problem without errors or an instance that may be retried' => [problemJson(ErrorCode::IdempotencyInFlight, ['errors' => [], 'instance' => null]), null],
        'a code that is not in the catalog' => [problemJson(fields: ['code' => 'no_such_code']), 'code'],
        'a status below 100' => [problemJson(fields: ['status' => 99]), 'status'],
        'a status in a string' => [problemJson(fields: ['status' => '422']), 'status'],
        'an empty detail' => [problemJson(fields: ['detail' => '']), 'detail'],
        'an instance that is empty' => [problemJson(fields: ['instance' => '']), 'instance'],
        'retryable missing' => [str_replace('"retryable":false,', '', problemJson()), 'retryable'],
        'a field path that is not one' => [problemJson(fields: ['errors' => [['code' => 'validation_failed', 'detail' => 'x', 'field' => 'fields..text']]]), 'errors[0].field'],
        'a field error without its field' => [problemJson(fields: ['errors' => [['code' => 'validation_failed', 'detail' => 'x']]]), 'errors[0].field'],
    ]);
});

it('accepts every envelope v1 the PHP codec writes, and refuses what it refuses at the same value', function (): void {
    $envelope = static fn (string $from, string $to): string => str_replace($from, $to, AGENT_ENVELOPE_JSON);

    crossCheck(new EnvelopeCodecV1, 'protocol/EnvelopeV1', 'validateEnvelopeV1', [
        'an agent\'s envelope' => [AGENT_ENVELOPE_JSON, null],
        'only the idempotency key' => ['{"idempotency_key":"order-1042"}', null],
        'nulls where null is allowed' => ['{"correlation_id":null,"idempotency_key":"order-1042","provenance":{"model":null,"prompt":null}}', null],
        'no idempotency key' => ['{"dry_run":true}', 'idempotency_key'],
        'an idempotency key with a space' => [$envelope('order-1042', 'order 1042'), 'idempotency_key'],
        'an idempotency key of 256 characters' => [$envelope('order-1042', str_repeat('k', 256)), 'idempotency_key'],
        'a dry run of null' => [$envelope('"dry_run":true', '"dry_run":null'), 'dry_run'],
        'a wait level that is not one' => [$envelope('"wait_level":"edge"', '"wait_level":"now"'), 'wait_level'],
        'an actor that is not a UUIDv7' => [$envelope('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a10', 'actor-10'), 'on_behalf_of[0]'],
        'a model without a name' => [$envelope('"name":"writer"', '"name":""'), 'provenance.model.name'],
        'a parameter with an unknown key' => [$envelope('{"name":"max_tokens","value":"800"}', '{"name":"max_tokens","value":"800","unit":"tokens"}'), 'provenance.parameters[0]'],
        'an empty prompt reference' => [$envelope('"prompts/summary/v3"', '""'), 'provenance.prompt'],
        'provenance that is a list' => ['{"idempotency_key":"order-1042","provenance":[]}', 'provenance'],
    ]);
});

it('refuses a value of the PHP codec\'s output planted wrong', function (JsonCodec $codec, string $module, string $validator, string $document, array $path, mixed $wrong, string $refusedAt): void {
    $output = $codec->encode($codec->decode($document, ClassificationAccess::Sensitive), ClassificationAccess::Sensitive);
    $verdicts = TypeScriptValidators::run(GENERATED, [
        ['module' => $module, 'validator' => $validator, 'document' => $output],
        ['module' => $module, 'validator' => $validator, 'document' => planted($output, $path, $wrong)],
    ]);

    expect($verdicts[0])->toBe(['valid' => true])
        ->and($verdicts[1]['valid'])->toBeFalse()
        ->and($verdicts[1]['path'] ?? null)->toBe($refusedAt);
})->with([
    'a decimal of a measurement as a number' => [new AppFixtureMeasurementCodecV1, 'records/AppFixtureMeasurementV1', 'validateAppFixtureMeasurementV1', fullMeasurementJson(), ['fixture_reading'], -12.5, 'fixture_reading'],
    'a date-time of a measurement\'s series without its offset' => [new AppFixtureMeasurementCodecV1, 'records/AppFixtureMeasurementV1', 'validateAppFixtureMeasurementV1', fullMeasurementJson(), ['fixture_series', 0, 'fixture_series_taken_at'], '2026-03-10T11:00:00', 'fixture_series[0].fixture_series_taken_at'],
    'a date of an article in another form' => [new AppFixtureArticleCodecV1, 'records/AppFixtureArticleV1', 'validateAppFixtureArticleV1', fullArticleJson(), ['fixture_published_on'], '09.03.2026', 'fixture_published_on'],
    'an article\'s rich text span without its key' => [new AppFixtureArticleCodecV1, 'records/AppFixtureArticleV1', 'validateAppFixtureArticleV1', fullArticleJson(), ['fixture_body', 0, 'children', 0, '_key'], '', 'fixture_body[0].children[0]._key'],
    'a receipt\'s WAL position in lower case' => [new ReceiptCodecV1, 'protocol/ReceiptV1', 'validateReceiptV1', COMMITTED_RECEIPT_JSON, ['consistency_token', 'lsn'], '16/b374d848', 'consistency_token.lsn'],
    'a problem\'s status as a string' => [new ProblemCodecV1, 'protocol/ProblemV1', 'validateProblemV1', problemJson(), ['status'], '422', 'status'],
    'an envelope\'s dry run as a string' => [new EnvelopeCodecV1, 'protocol/EnvelopeV1', 'validateEnvelopeV1', AGENT_ENVELOPE_JSON, ['dry_run'], 'yes', 'dry_run'],
]);
