<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs;

use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\Omitted;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use DateTimeImmutable;
use LogicException;
use Workbench\App\Cms\Generated\Boundary\AppFixtureArticleCodecV1;
use Workbench\App\Cms\Generated\Boundary\AppFixtureMeasurementCodecV1;
use Workbench\App\Cms\Generated\Domain\Dto\AppFixtureArticleV1;
use Workbench\App\Cms\Generated\Domain\Dto\AppFixtureArticleV1FixtureEmbargo;
use Workbench\App\Cms\Generated\Domain\Dto\AppFixtureArticleV1FixtureSources;
use Workbench\App\Cms\Generated\Domain\Dto\AppFixtureMeasurementV1;

/*
 * The workbench's generated record codecs, contract version 1 (PRD 8.9, GUARDRAILS 2.2). Every core
 * field type of the fixture types goes through encode() into the canonical JSON written out by
 * hand below, and back through decode() into an equal record: text, long text, integer, decimal,
 * boolean, date, datetime, select once and multiple, rich text, and a group once and repeated.
 * Null stays null, an optional field that is missing stays Omitted, and a field classified above
 * the caller's classification access is absent from the encoding and refused in a document.
 */

const ARTICLE_ID = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01';

const MEASUREMENT_ID = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02';

/** The article with every field set, as a caller with confidential access sees it. */
const ARTICLE_JSON = '{"cms_id":"'.ARTICLE_ID.'",'
    .'"fixture_body":[{"_key":"a","_type":"block","children":[{"_key":"a1","_type":"span","marks":["strong","l"],"text":"Read the report"}],"markDefs":[{"_key":"l","_type":"link","href":"https://example.org/report"}],"style":"h2"}],'
    .'"fixture_embargo":{"fixture_embargo_reason":"Waiting for the minister.","fixture_embargo_until":"2026-03-10T08:00:00.000000Z"},'
    .'"fixture_featured":true,'
    .'"fixture_published_on":"2026-03-09",'
    .'"fixture_reading_minutes":7,'
    .'"fixture_sources":[{"fixture_source_title":"The report","fixture_source_url":"https://example.org/report"},{"fixture_source_title":"An interview"}],'
    .'"fixture_title":"Budget: the report is out",'
    .'"fixture_topics":["fixture_politics","fixture_science"]}';

/** The measurement with every field set, as a caller with internal access sees it. */
const MEASUREMENT_JSON = '{"cms_id":"'.MEASUREMENT_ID.'","fixture_measured_at":"2026-03-10T11:59:59.250000Z","fixture_note":"Sensor cleaned first.","fixture_reading":"-12.500","fixture_scale":"fixture_celsius","fixture_station":"DK-042"}';

/**
 * The rich text of the article: one heading with a decorator and a link.
 */
function articleBody(): ListValue
{
    $body = JsonValues::fieldValue(
        JsonText::decode('{"b":[{"_type":"block","_key":"a","style":"h2","markDefs":[{"_type":"link","_key":"l","href":"https://example.org/report"}],"children":[{"_type":"span","_key":"a1","text":"Read the report","marks":["strong","l"]}]}]}')->b,
        new FieldPath('fixture_body'),
    );

    return $body instanceof ListValue ? $body : throw new LogicException('The body is not a list.');
}

function article(): AppFixtureArticleV1
{
    return new AppFixtureArticleV1(
        cmsId: EntryId::fromString(ARTICLE_ID),
        fixtureBody: articleBody(),
        fixtureEmbargo: new AppFixtureArticleV1FixtureEmbargo(
            fixtureEmbargoReason: 'Waiting for the minister.',
            fixtureEmbargoUntil: new DateTimeImmutable('2026-03-10T09:00:00+01:00'),
        ),
        fixtureFeatured: true,
        fixturePublishedOn: new DateTimeImmutable('2026-03-09T00:00:00Z'),
        fixtureReadingMinutes: 7,
        fixtureSources: [
            new AppFixtureArticleV1FixtureSources(fixtureSourceTitle: 'The report', fixtureSourceUrl: 'https://example.org/report'),
            new AppFixtureArticleV1FixtureSources(fixtureSourceTitle: 'An interview', fixtureSourceUrl: Omitted::Field),
        ],
        fixtureTitle: 'Budget: the report is out',
        fixtureTopics: ['fixture_politics', 'fixture_science'],
    );
}

function measurement(): AppFixtureMeasurementV1
{
    return new AppFixtureMeasurementV1(
        cmsId: EntryId::fromString(MEASUREMENT_ID),
        fixtureAlerts: Omitted::Field,
        fixtureCalibrated: Omitted::Field,
        fixtureCalibratedOn: Omitted::Field,
        fixtureMeasuredAt: new DateTimeImmutable('2026-03-10T12:59:59.25+01:00'),
        fixtureNote: 'Sensor cleaned first.',
        fixtureReading: '-12.5',
        fixtureRemark: Omitted::Field,
        fixtureSamples: Omitted::Field,
        fixtureScale: 'fixture_celsius',
        fixtureSensor: Omitted::Field,
        fixtureSeries: Omitted::Field,
        fixtureStation: 'DK-042',
    );
}

/**
 * The code, the path and the reason of the failure of a decode.
 *
 * @param  callable(): object  $decode
 * @return array{string, ?string, string}
 */
function refusedDocument(callable $decode): array
{
    try {
        $decode();
    } catch (DecodingFailed $failure) {
        return [$failure->errorCode->value, $failure->path?->toString(), $failure->reason];
    }

    throw new LogicException('The document was not refused.');
}

it('encodes every field type into the canonical JSON and decodes it into an equal record', function (): void {
    $articles = new AppFixtureArticleCodecV1;
    $measurements = new AppFixtureMeasurementCodecV1;

    expect($articles->encode(article(), ClassificationAccess::Confidential))->toBe(ARTICLE_JSON)
        ->and($articles->decode(ARTICLE_JSON, ClassificationAccess::Confidential))->toEqual(article())
        ->and($articles->encode($articles->decode(ARTICLE_JSON, ClassificationAccess::Confidential), ClassificationAccess::Confidential))->toBe(ARTICLE_JSON)
        ->and($measurements->encode(measurement(), ClassificationAccess::Internal))->toBe(MEASUREMENT_JSON)
        ->and($measurements->encode($measurements->decode(MEASUREMENT_JSON, ClassificationAccess::Internal), ClassificationAccess::Internal))->toBe(MEASUREMENT_JSON)
        ->and(AppFixtureArticleCodecV1::VERSION)->toBe(1);

    $decoded = $measurements->decode(MEASUREMENT_JSON, ClassificationAccess::Internal);

    expect($decoded->fixtureReading)->toBe('-12.500')
        ->and($decoded->fixtureMeasuredAt->format('Y-m-d\TH:i:s.uP'))->toBe('2026-03-10T11:59:59.250000+00:00')
        ->and($decoded->cmsId->equals(EntryId::fromString(MEASUREMENT_ID)))->toBeTrue();
});

it('decodes the same record from any key order, whitespace and offset, and encodes it canonically', function (): void {
    $document = <<<'JSON'
        {
          "fixture_station": "DK-042", "fixture_scale": "fixture_celsius",
          "fixture_reading": "-0012.5", "fixture_note": "Sensor cleaned first.",
          "fixture_measured_at": "2026-03-10T13:59:59.25+02:00",
          "cms_id": "0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"
        }
        JSON;
    $codec = new AppFixtureMeasurementCodecV1;

    expect($codec->encode($codec->decode($document, ClassificationAccess::Internal), ClassificationAccess::Internal))->toBe(MEASUREMENT_JSON);
});

it('keeps null as null and a missing optional field as Omitted', function (): void {
    $codec = new AppFixtureArticleCodecV1;
    $withNulls = new AppFixtureArticleV1(
        cmsId: EntryId::fromString(ARTICLE_ID),
        fixtureBody: null,
        fixtureEmbargo: null,
        fixtureFeatured: false,
        fixturePublishedOn: null,
        fixtureReadingMinutes: null,
        fixtureSources: null,
        fixtureTitle: null,
        fixtureTopics: null,
    );
    $nullJson = '{"cms_id":"'.ARTICLE_ID.'","fixture_body":null,"fixture_embargo":null,"fixture_featured":false,"fixture_published_on":null,"fixture_reading_minutes":null,"fixture_sources":null,"fixture_title":null,"fixture_topics":null}';
    $missing = new AppFixtureArticleV1(
        cmsId: EntryId::fromString(ARTICLE_ID),
        fixtureBody: Omitted::Field,
        fixtureEmbargo: Omitted::Field,
        fixtureFeatured: false,
        fixturePublishedOn: Omitted::Field,
        fixtureReadingMinutes: Omitted::Field,
        fixtureSources: Omitted::Field,
        fixtureTitle: Omitted::Field,
        fixtureTopics: Omitted::Field,
    );
    $missingJson = '{"cms_id":"'.ARTICLE_ID.'","fixture_featured":false}';

    expect($codec->encode($withNulls, ClassificationAccess::Confidential))->toBe($nullJson)
        ->and($codec->decode($nullJson, ClassificationAccess::Confidential))->toEqual($withNulls)
        ->and($codec->encode($missing, ClassificationAccess::Confidential))->toBe($missingJson)
        ->and($codec->decode($missingJson, ClassificationAccess::Confidential))->toEqual($missing)
        ->and($codec->decode('{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_sources":[{"fixture_source_title":"A"}]}', ClassificationAccess::Internal)->fixtureSources)
        ->toEqual([new AppFixtureArticleV1FixtureSources(fixtureSourceTitle: 'A', fixtureSourceUrl: Omitted::Field)]);
});

it('leaves out every field classified above the caller\'s classification access', function (ClassificationAccess $access, string $article, string $measurement): void {
    expect(new AppFixtureArticleCodecV1()->encode(article(), $access))->toBe($article)
        ->and(new AppFixtureMeasurementCodecV1()->encode(measurement(), $access))->toBe($measurement);
})->with([
    'public' => [
        ClassificationAccess::Public,
        '{"cms_id":"'.ARTICLE_ID.'","fixture_body":[{"_key":"a","_type":"block","children":[{"_key":"a1","_type":"span","marks":["strong","l"],"text":"Read the report"}],"markDefs":[{"_key":"l","_type":"link","href":"https://example.org/report"}],"style":"h2"}],"fixture_featured":true,"fixture_published_on":"2026-03-09","fixture_reading_minutes":7,"fixture_title":"Budget: the report is out","fixture_topics":["fixture_politics","fixture_science"]}',
        '{"cms_id":"'.MEASUREMENT_ID.'","fixture_measured_at":"2026-03-10T11:59:59.250000Z","fixture_reading":"-12.500","fixture_scale":"fixture_celsius"}',
    ],
    'internal' => [
        ClassificationAccess::Internal,
        '{"cms_id":"'.ARTICLE_ID.'","fixture_body":[{"_key":"a","_type":"block","children":[{"_key":"a1","_type":"span","marks":["strong","l"],"text":"Read the report"}],"markDefs":[{"_key":"l","_type":"link","href":"https://example.org/report"}],"style":"h2"}],"fixture_featured":true,"fixture_published_on":"2026-03-09","fixture_reading_minutes":7,"fixture_sources":[{"fixture_source_title":"The report","fixture_source_url":"https://example.org/report"},{"fixture_source_title":"An interview"}],"fixture_title":"Budget: the report is out","fixture_topics":["fixture_politics","fixture_science"]}',
        MEASUREMENT_JSON,
    ],
    'sensitive' => [ClassificationAccess::Sensitive, ARTICLE_JSON, MEASUREMENT_JSON],
]);

it('gives the record as the caller may see it, with the fields above its access Omitted', function (): void {
    $visible = article()->visibleTo(ClassificationAccess::Internal);

    expect($visible->fixtureEmbargo)->toBe(Omitted::Field)
        ->and($visible->fixtureSources)->toEqual(article()->fixtureSources)
        ->and($visible->fixtureTitle)->toBe('Budget: the report is out')
        ->and(measurement()->visibleTo(ClassificationAccess::Public)->fixtureStation)->toBe(Omitted::Field)
        ->and(new AppFixtureArticleCodecV1()->decode(
            '{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true}',
            ClassificationAccess::Public,
        ))->toEqual(new AppFixtureArticleV1(
            cmsId: EntryId::fromString(ARTICLE_ID),
            fixtureBody: Omitted::Field,
            fixtureEmbargo: Omitted::Field,
            fixtureFeatured: true,
            fixturePublishedOn: Omitted::Field,
            fixtureReadingMinutes: Omitted::Field,
            fixtureSources: Omitted::Field,
            fixtureTitle: Omitted::Field,
            fixtureTopics: Omitted::Field,
        ));
});

it('refuses a document that breaks the contract, naming the value', function (string $document, ClassificationAccess $access, array $failure): void {
    expect(refusedDocument(static fn (): AppFixtureArticleV1 => new AppFixtureArticleCodecV1()->decode($document, $access)))->toBe($failure);
})->with([
    'a key twice' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_featured":false}', ClassificationAccess::Public, ['json_malformed', null, 'an object has the key "fixture_featured" twice']],
    'not JSON' => ['{"cms_id":', ClassificationAccess::Public, ['json_malformed', null, 'the document is not well-formed JSON, or nests more than 63 arrays or objects inside one another: Syntax error']],
    'a list' => ['[]', ClassificationAccess::Public, ['json_malformed', null, 'the document is not a JSON object']],
    'an unknown key' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_subtitle":"x"}', ClassificationAccess::Public, ['json_invalid', null, 'has the key "fixture_subtitle", which is not a field of the contract']],
    'an unknown key in a group' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_sources":[{"fixture_source_title":"A","fixture_pages":2}]}', ClassificationAccess::Internal, ['json_invalid', 'fixture_sources[0]', 'has the key "fixture_pages", which is not a field of the contract']],
    'a required field missing' => ['{"cms_id":"'.ARTICLE_ID.'"}', ClassificationAccess::Public, ['json_invalid', 'fixture_featured', 'is missing, and the field is required']],
    'the id missing' => ['{"fixture_featured":true}', ClassificationAccess::Public, ['json_invalid', 'cms_id', 'is missing, and the field is required']],
    'a required field null' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":null}', ClassificationAccess::Public, ['json_invalid', 'fixture_featured', 'is null, and the field is required']],
    'an internal field given to public access' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_sources":[]}', ClassificationAccess::Public, ['json_invalid', 'fixture_sources', 'is classified internal, above the classification access public']],
    'a confidential field given to internal access' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_embargo":null}', ClassificationAccess::Internal, ['json_invalid', 'fixture_embargo', 'is classified confidential, above the classification access internal']],
    'a boolean of the wrong type' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":"yes"}', ClassificationAccess::Public, ['json_invalid', 'fixture_featured', 'is not a boolean']],
    'an integer above its maximum' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_reading_minutes":601}', ClassificationAccess::Public, ['json_invalid', 'fixture_reading_minutes', 'is 601, more than the maximum 600']],
    'a date before its minimum' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_published_on":"1999-12-31"}', ClassificationAccess::Public, ['json_invalid', 'fixture_published_on', 'is 1999-12-31, before the minimum 2000-01-01']],
    'text above its default length' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_title":"'.str_repeat('x', 256).'"}', ClassificationAccess::Public, ['json_invalid', 'fixture_title', 'has 256 characters, more than the 255 the field allows']],
    'a topic that is no option' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_topics":["fixture_sport"]}', ClassificationAccess::Public, ['json_invalid', 'fixture_topics[0]', 'is not one of fixture_politics, fixture_science, fixture_culture']],
    'a topic twice' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_topics":["fixture_science","fixture_science"]}', ClassificationAccess::Public, ['json_invalid', 'fixture_topics[1]', 'is an item the list already has']],
    'too many topics' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_topics":["fixture_politics","fixture_science","fixture_culture"]}', ClassificationAccess::Public, ['json_invalid', 'fixture_topics', 'has 3 items, more than the 2 the field allows']],
    'a source without a title' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_sources":[{"fixture_source_url":"https://example.org"}]}', ClassificationAccess::Internal, ['json_invalid', 'fixture_sources[0].fixture_source_title', 'is missing, and the field is required']],
    'a source URL that is not one' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_sources":[{"fixture_source_title":"A","fixture_source_url":"example.org"}]}', ClassificationAccess::Internal, ['json_invalid', 'fixture_sources[0].fixture_source_url', 'is not in the format url']],
    'an embargo without its time' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_embargo":{}}', ClassificationAccess::Confidential, ['json_invalid', 'fixture_embargo.fixture_embargo_until', 'is missing, and the field is required']],
    'an embargo time without an offset' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_embargo":{"fixture_embargo_until":"2026-03-10T08:00:00"}}', ClassificationAccess::Confidential, ['json_invalid', 'fixture_embargo.fixture_embargo_until', 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z']],
    'rich text with a style the field allows but no span' => ['{"cms_id":"'.ARTICLE_ID.'","fixture_featured":true,"fixture_body":[{"_type":"block","_key":"a","children":[]}]}', ClassificationAccess::Public, ['json_invalid', 'fixture_body[0].children', 'is empty: a block has at least one span']],
    'an id that is no UUIDv7' => ['{"cms_id":"'.MEASUREMENT_ID.'x","fixture_featured":true}', ClassificationAccess::Public, ['json_invalid', 'cms_id', 'is not a valid id: Expected a UUID in the form xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx with hex digits, got "'.MEASUREMENT_ID.'x".']],
]);

it('refuses a measurement whose decimal or choice breaks its field\'s rules', function (string $document, array $failure): void {
    expect(refusedDocument(static fn (): AppFixtureMeasurementV1 => new AppFixtureMeasurementCodecV1()->decode($document, ClassificationAccess::Internal)))->toBe($failure);
})->with([
    'a reading with four decimals' => ['{"cms_id":"'.MEASUREMENT_ID.'","fixture_measured_at":"2026-03-10T12:00:00Z","fixture_reading":"1.2345","fixture_scale":"fixture_kelvin"}', ['json_invalid', 'fixture_reading', 'is not a decimal number in a string with at most 9 digits before the point and 3 after it']],
    'a reading as a JSON number' => ['{"cms_id":"'.MEASUREMENT_ID.'","fixture_measured_at":"2026-03-10T12:00:00Z","fixture_reading":1.5,"fixture_scale":"fixture_kelvin"}', ['json_invalid', 'fixture_reading', 'is not a decimal number in a string with at most 9 digits before the point and 3 after it']],
    'a scale that is no option' => ['{"cms_id":"'.MEASUREMENT_ID.'","fixture_measured_at":"2026-03-10T12:00:00Z","fixture_reading":"1","fixture_scale":"fixture_fahrenheit"}', ['json_invalid', 'fixture_scale', 'is not one of fixture_celsius, fixture_kelvin']],
    'a station above its length' => ['{"cms_id":"'.MEASUREMENT_ID.'","fixture_measured_at":"2026-03-10T12:00:00Z","fixture_reading":"1","fixture_scale":"fixture_kelvin","fixture_station":"'.str_repeat('s', 41).'"}', ['json_invalid', 'fixture_station', 'has 41 characters, more than the 40 the field allows']],
]);
