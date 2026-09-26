<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Codecs;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/*
 * The blueprint schema v1, first edition (PRD 11.12, 13.1, 14.1): resources/schemas/blueprint.v1.json
 * against YAML fixtures read the way the reader of cms:generate reads them, with symfony/yaml and
 * PARSE_OBJECT_FOR_MAP so that an empty map stays an object, and validated with opis'
 * CompliantValidator, which never writes defaults into the data. Every invalid fixture fails at
 * exactly the JSON pointer it is written to break, and the examples on the reference page are the
 * valid fixtures, byte for byte.
 */

const BLUEPRINT_PACKAGE = __DIR__.'/../..';

const BLUEPRINT_SCHEMA = BLUEPRINT_PACKAGE.'/resources/schemas/blueprint.v1.json';

const BLUEPRINT_PAGE = BLUEPRINT_PACKAGE.'/resources/schemas/blueprint.v1.md';

const BLUEPRINT_FIXTURES = __DIR__.'/Fixtures/Blueprint';

function blueprintRead(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Cannot read '.$path.'.');
    }

    return $contents;
}

/**
 * The validation errors of a YAML fixture by JSON pointer, or an empty array when it is valid.
 *
 * @return array<string, list<string>>
 */
function blueprintErrors(string $fixture): array
{
    $document = Yaml::parse(blueprintRead(BLUEPRINT_FIXTURES.'/'.$fixture), Yaml::PARSE_OBJECT_FOR_MAP);

    $validator = new CompliantValidator;
    $validator->setMaxErrors(100);

    $error = $validator->validate($document, blueprintRead(BLUEPRINT_SCHEMA))->error();

    if (! $error instanceof ValidationError) {
        return [];
    }

    /** @var array<string, list<string>> $errors */
    $errors = new ErrorFormatter()->format($error, true);

    return $errors;
}

/**
 * @return array<string, mixed>
 */
function blueprintSchema(): array
{
    $schema = json_decode(blueprintRead(BLUEPRINT_SCHEMA), true, 512, JSON_THROW_ON_ERROR);

    expect($schema)->toBeArray();

    /** @var array<string, mixed> $schema */
    return $schema;
}

/**
 * Every `$id` key at any depth of a decoded JSON value.
 */
function blueprintIdKeys(mixed $value): int
{
    if (! is_array($value)) {
        return 0;
    }

    $count = array_key_exists('$id', $value) ? 1 : 0;

    foreach ($value as $inner) {
        $count += blueprintIdKeys($inner);
    }

    return $count;
}

/**
 * The field types used in a list of decoded fields, groups included.
 *
 * @return list<string>
 */
function blueprintFieldTypes(mixed $fields): array
{
    if (! is_array($fields)) {
        return [];
    }

    $types = [];

    foreach ($fields as $field) {
        if (! is_array($field)) {
            continue;
        }

        if (is_string($field['type'] ?? null)) {
            $types[] = $field['type'];
        }

        $types = [...$types, ...blueprintFieldTypes($field['fields'] ?? null)];
    }

    return $types;
}

/**
 * The fenced YAML blocks of the reference page, keyed by the fixture that the comment above each
 * one names.
 *
 * @return array<string, string>
 */
function blueprintPageExamples(): array
{
    preg_match_all(
        '/^<!-- fixture: (?<path>\S+) -->\n```yaml\n(?<body>.*?)^```$/ms',
        blueprintRead(BLUEPRINT_PAGE),
        $matches,
        PREG_SET_ORDER,
    );

    $examples = [];

    foreach ($matches as $match) {
        $examples[$match['path']] = $match['body'];
    }

    return $examples;
}

/**
 * Each invalid fixture with the JSON pointer it fails at and a part of the message there.
 *
 * @return array<string, array{string, string, string}>
 */
function blueprintInvalidCases(): array
{
    return [
        'a handle with an uppercase letter' => ['handle-uppercase.yaml', '/fields/0/handle', 'should match pattern'],
        'a handle with a double underscore' => ['handle-double-underscore.yaml', '/fields/0/handle', 'should match pattern'],
        'the reserved handle ext' => ['handle-ext.yaml', '/fields/0/handle', 'must not match schema'],
        'a handle with the reserved prefix cms_' => ['handle-cms-prefix.yaml', '/fields/0/handle', 'must not match schema'],
        'a top-level field without a classification' => ['missing-classification.yaml', '/fields/0', '(classification)'],
        'a field without a description and without agents: false' => ['missing-description.yaml', '/fields/0', '(description)'],
        'history: audit_only' => ['history-audit-only-underscore.yaml', '/capabilities/history', 'enum'],
        'an unquoted date in min' => ['unquoted-date.yaml', '/fields/1/min', 'must match the type: string'],
        'a decimal without scale' => ['decimal-without-scale.yaml', '/fields/0', '(scale)'],
        'a classification on a field inside a group' => ['classification-in-group.yaml', '/fields/0/fields/0', 'must not match schema'],
        'the field type relation' => ['field-type-relation.yaml', '/fields/0/type', 'enum'],
        'kind: fieldset' => ['kind-fieldset.yaml', '/kind', 'enum'],
        'localization: variants' => ['localization-variants.yaml', '/capabilities/localization', 'enum'],
        'an extension without fields' => ['extension-without-fields.yaml', '/', '(fields)'],
        'blueprint: 2' => ['blueprint-2.yaml', '/blueprint', 'const'],
        'a missing blueprint' => ['missing-blueprint.yaml', '/', '(blueprint)'],
    ];
}

it('is JSON Schema draft 2020-12 without an $id, and requires the marker blueprint: 1', function (): void {
    $schema = blueprintSchema();

    expect($schema['$schema'] ?? null)->toBe('https://json-schema.org/draft/2020-12/schema')
        ->and(blueprintIdKeys($schema))->toBe(0)
        ->and($schema['required'] ?? null)->toContain('blueprint')
        ->and($schema)->toHaveKey('properties.blueprint.const')
        ->and(data_get($schema, 'properties.blueprint.const'))->toBe(1);
});

it('accepts the valid fixtures', function (string $fixture): void {
    expect(blueprintErrors($fixture))->toBe([]);
})->with([
    'a type with every core field type' => ['valid/article.yaml'],
    'an extension' => ['valid/extension.yaml'],
    'a type with the addon field type acme:colour and its options' => ['valid/addon-field-type.yaml'],
]);

it('covers every core field type of the schema in the valid article', function (): void {
    $core = data_get(blueprintSchema(), '$defs.field.properties.type.anyOf.0.enum');
    $article = Yaml::parse(blueprintRead(BLUEPRINT_FIXTURES.'/valid/article.yaml'), Yaml::PARSE_OBJECT_FOR_MAP);
    $decoded = json_decode(json_encode($article, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($core) || $core === [] || ! is_array($decoded)) {
        throw new RuntimeException('The schema has no core field types, or the article is not a map.');
    }

    $used = blueprintFieldTypes($decoded['fields'] ?? null);
    $missing = array_values(array_filter($core, static fn (mixed $type): bool => ! in_array($type, $used, true)));

    expect($missing)->toBe([]);
});

it('refuses each invalid fixture at the JSON pointer it breaks', function (string $fixture, string $pointer, string $message): void {
    $errors = blueprintErrors('invalid/'.$fixture);

    expect(array_keys($errors))->toBe([$pointer])
        ->and(implode("\n", $errors[$pointer]))->toContain($message);
})->with(blueprintInvalidCases());

it('has a case for every invalid fixture', function (): void {
    $files = array_map(basename(...), glob(BLUEPRINT_FIXTURES.'/invalid/*.yaml') ?: []);
    $cases = array_map(static fn (array $case): string => $case[0], array_values(blueprintInvalidCases()));
    sort($cases);

    expect($files)->toBe($cases);
});

it('embeds each valid fixture on the reference page, byte for byte', function (): void {
    $examples = blueprintPageExamples();

    expect(array_keys($examples))->toBe([
        'tests/Codecs/Fixtures/Blueprint/valid/article.yaml',
        'tests/Codecs/Fixtures/Blueprint/valid/extension.yaml',
        'tests/Codecs/Fixtures/Blueprint/valid/addon-field-type.yaml',
    ]);

    foreach ($examples as $path => $body) {
        expect($body)->toBe(blueprintRead(BLUEPRINT_PACKAGE.'/'.$path));
    }
});

it('has no fenced YAML on the reference page that is not a fixture', function (): void {
    expect(substr_count(blueprintRead(BLUEPRINT_PAGE), '```yaml'))->toBe(count(blueprintPageExamples()));
});

it('says on the reference page that dates must be quoted', function (): void {
    expect(blueprintRead(BLUEPRINT_PAGE))
        ->toContain('## Dates must be quoted')
        ->toContain("Write every date and time in quotes: `min: '2026-01-01'`, not `min: 2026-01-01`.");
});
