<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\FixtureSchemaParser;
use Cbox\Cms\Generators\Schema\Domain\FieldDefinition;
use Cbox\Cms\Generators\Schema\Domain\TypeDefinition;
use PHPUnit\Framework\Assert;

/*
 * The M0 schema format: `format: m0-provisional`, types with a handle, a label and fields, and
 * fields with a handle and a type. Every problem is reported at once, with where it is.
 */

function parseFailure(mixed $document): GenerationFailed
{
    try {
        new FixtureSchemaParser()->parse($document, 'fixture.yaml');
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    Assert::fail('The parser accepted an invalid schema.');
}

/**
 * @param  list<array<string, mixed>>  $types
 * @return array<string, mixed>
 */
function schemaDocument(array $types): array
{
    return ['format' => 'm0-provisional', 'types' => $types];
}

it('parses the M0 format into types and fields sorted by handle', function (): void {
    $schema = new FixtureSchemaParser()->parse(schemaDocument([
        ['handle' => 'page', 'label' => 'Page', 'fields' => [['handle' => 'title', 'type' => 'text']]],
        ['handle' => 'article', 'label' => 'Artikel på dansk', 'fields' => [
            ['handle' => 'title', 'type' => 'text'],
            ['handle' => 'body', 'type' => 'markdown'],
        ]],
    ]), 'fixture.yaml');

    expect(array_map(static fn (TypeDefinition $type): string => $type->handle->value, $schema->types))->toBe(['article', 'page'])
        ->and($schema->types[0]->label)->toBe('Artikel på dansk')
        ->and(array_map(static fn (FieldDefinition $field): array => [$field->handle->value, $field->type->value], $schema->types[0]->fields))
        ->toBe([['body', 'markdown'], ['title', 'text']]);
});

it('requires format m0-provisional', function (mixed $format): void {
    $failed = parseFailure(['format' => $format, 'types' => [['handle' => 'page', 'label' => 'Page', 'fields' => [['handle' => 'title', 'type' => 'text']]]]]);

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaFormat])
        ->and($failed->getMessage())->toContain('set "format: m0-provisional"')
        ->and($failed->getMessage())->toContain('does not pre-empt the blueprint schema v1');
})->with(['blueprint v1' => 'blueprint-v1', 'a number' => 1, 'null' => null]);

it('reports every problem in one run, with where it is', function (): void {
    $failed = parseFailure([
        'types' => [
            ['handle' => 'Article', 'label' => 'Article', 'fields' => [['handle' => 'title', 'type' => 'text']]],
            ['handle' => 'page', 'label' => 7, 'fields' => []],
            ['handle' => 'post', 'label' => 'Post', 'fields' => [['handle' => 'title'], ['handle' => 'body', 'type' => 'Rich Text', 'required' => true]]],
            'news',
        ],
        'extra' => true,
    ]);

    expect(array_map(static fn (GenerationProblem $problem): string => $problem->describe(), $failed->problems))->toBe([
        '[generate_schema_format] fixture.yaml: set "format: m0-provisional". It is the only format cms:generate reads in milestone 0. It is provisional and does not pre-empt the blueprint schema v1 of milestone 1.',
        '[generate_schema_invalid] fixture.yaml, types[0].handle: "Article" is not a handle. Use lowercase snake_case that starts with a letter and has at most 63 characters, such as "blog_post".',
        '[generate_schema_invalid] fixture.yaml, types[1].fields: must be a list with at least one item',
        '[generate_schema_invalid] fixture.yaml, types[1].label: must be a string',
        '[generate_schema_invalid] fixture.yaml, types[2].fields[0].type: must be a string',
        '[generate_schema_invalid] fixture.yaml, types[2].fields[0]: is missing type',
        '[generate_schema_invalid] fixture.yaml, types[2].fields[1].type: "Rich Text" is not a handle. Use lowercase snake_case that starts with a letter and has at most 63 characters, such as "blog_post".',
        '[generate_schema_invalid] fixture.yaml, types[2].fields[1]: has the unknown key required; the allowed keys are handle, type',
        '[generate_schema_invalid] fixture.yaml, types[3]: must be a mapping with the keys handle, label, fields',
        '[generate_schema_invalid] fixture.yaml: has the unknown key extra; the allowed keys are format, types',
        '[generate_schema_invalid] fixture.yaml: is missing format',
    ]);
});

it('refuses two types with the same handle', function (): void {
    $type = ['handle' => 'page', 'label' => 'Page', 'fields' => [['handle' => 'title', 'type' => 'text']]];

    $failed = parseFailure(schemaDocument([$type, $type]));

    expect($failed->codes())->toBe([GenerateErrorCode::DuplicateType])
        ->and($failed->getMessage())->toContain('More than one type has the handle "page".');
});

it('refuses two fields of one type with the same handle', function (): void {
    $failed = parseFailure(schemaDocument([[
        'handle' => 'page',
        'label' => 'Page',
        'fields' => [['handle' => 'title', 'type' => 'text'], ['handle' => 'title', 'type' => 'markdown']],
    ]]));

    expect($failed->codes())->toBe([GenerateErrorCode::DuplicateField])
        ->and($failed->getMessage())->toContain('types[0]: Type "page" has more than one field with the handle "title".');
});

it('refuses a document that is not a mapping, and a schema without types', function (mixed $document, string $message): void {
    $failed = parseFailure($document);

    expect($failed->codes())->toContain(GenerateErrorCode::SchemaInvalid)
        ->and($failed->getMessage())->toContain($message);
})->with([
    'an empty file' => [null, 'fixture.yaml: must be a mapping with the keys format, types'],
    'a list' => [[1, 2], 'fixture.yaml: must be a mapping with the keys format, types'],
    'no types' => [['format' => 'm0-provisional', 'types' => []], 'fixture.yaml, types: must be a list with at least one item'],
    'types as a mapping' => [['format' => 'm0-provisional', 'types' => ['page' => []]], 'fixture.yaml, types: must be a list with at least one item'],
]);

it('refuses handles that are not lowercase snake_case of at most 63 characters', function (string $handle): void {
    $failed = parseFailure(schemaDocument([['handle' => $handle, 'label' => 'X', 'fields' => [['handle' => 'title', 'type' => 'text']]]]));

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid])
        ->and($failed->getMessage())->toContain('is not a handle');
})->with(['Page', '1page', '_page', 'page_', 'page__x', 'blog-post', 'blog post', '', "page\n", str_repeat('a', 64)]);

it('accepts a handle of exactly 63 characters', function (): void {
    $schema = new FixtureSchemaParser()->parse(schemaDocument([['handle' => str_repeat('a', 63), 'label' => 'X', 'fields' => [['handle' => 'title', 'type' => 'text']]]]), 'fixture.yaml');

    expect($schema->types[0]->handle->value)->toBe(str_repeat('a', 63));
});

it('refuses a label that is empty, has several lines or control characters, or is too long', function (string $label): void {
    $failed = parseFailure(schemaDocument([['handle' => 'page', 'label' => $label, 'fields' => [['handle' => 'title', 'type' => 'text']]]]));

    expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid])
        ->and($failed->getMessage())->toContain('The label of type "page" must be one line of text');
})->with(['', '   ', "Two\nlines", "Tab\there", str_repeat('x', 256), "\xC3\x28"]);
