<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Descriptor;

use Cbox\Cms\Contracts\Editors\EditorField;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\FieldFallback as EditorFallback;
use Cbox\Cms\Core\Codecs\Boundary\Generated\TypeEditorCodecV1;
use Cbox\Cms\Generators\Descriptor\Boundary\TypeDescriptorJson;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Schema\Domain\FieldFallback;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\TypeEditors\Boundary\RuleJson;
use Cbox\Cms\Generators\TypeEditors\Domain\TypeEditorCompiler;

/*
 * The localized example type (PRD 10.1, 10.3, 11.6, 11.12): blog:post with localization variants,
 * extended by the app, compiles to the committed golden descriptor, editor descriptor, PHP and
 * TypeScript, and the descriptor says which variant holds each field and how it falls back: the
 * translatable fields with their fallback, the nested fields of a translatable group with the
 * group's, and the shared fields with the defaults.
 */

function localizedDescriptor(): TypeDescriptor
{
    $types = LocalizedExample::compile()->types;

    expect($types)->toHaveCount(1);

    return $types[0];
}

/**
 * @return array<string, array{bool, string}> each top-level field's translatable and fallback, by column
 */
function localizedFields(TypeDescriptor $type): array
{
    $fields = [];

    foreach ($type->fields as $field) {
        $fields[$field->column->name ?? $field->handle->value] = [$field->translatable, $field->fallback->value];
    }

    return $fields;
}

it('compiles the localized example to the committed golden descriptor', function (): void {
    expect(TypeDescriptorJson::encode(localizedDescriptor()))
        ->toBe((string) file_get_contents(LocalizedExample::GOLDEN), 'The descriptor of the localized example differs from '.LocalizedExample::GOLDEN.'. Review the difference; when it is intended, write the new descriptor there.');
});

it('compiles the localized example to the committed golden editor descriptor, as its codec writes it', function (): void {
    $editor = new TypeEditorCompiler(RuleJson::of(...))->compile(localizedDescriptor());
    $json = new TypeEditorCodecV1()->encode($editor, ClassificationAccess::Sensitive);
    $pretty = json_encode(json_decode($json, false, 64, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    $byPath = [];

    foreach ($editor->fields as $field) {
        $byPath[$field->path] = [$field->translatable, $field->fallback];
    }

    expect($pretty)->toBe((string) file_get_contents(LocalizedExample::GOLDEN_EDITOR), 'The editor descriptor of the localized example differs from editor.json. Review the difference; when it is intended, write the new descriptor there.')
        ->and($editor->capabilities->localization->value)->toBe('variants')
        ->and($byPath['title'])->toBe([true, EditorFallback::Variant])
        ->and($byPath['teaser'])->toBe([true, EditorFallback::Field])
        ->and($byPath['quotes'])->toBe([true, EditorFallback::Variant])
        ->and($byPath['quotes.text'])->toBe([true, EditorFallback::Variant])
        ->and($byPath['published_on'])->toBe([false, EditorFallback::Variant])
        ->and($byPath['ext.app.subtitle'])->toBe([true, EditorFallback::Variant])
        ->and($byPath['ext.app.tag'])->toBe([false, EditorFallback::Variant])
        ->and(array_map(static fn (EditorField $field): string => $field->path, $editor->visibleTo(ClassificationAccess::Public, false)->fields))->not->toContain('author_email');
});

it('generates exactly the committed golden PHP and TypeScript of the localized example', function (): void {
    $php = LocalizedExample::php();
    $typeScript = LocalizedExample::typeScript();

    expect(array_keys($php))->toBe(array_keys(LocalizedExample::goldenPhp()), 'The generated files differ from the golden files in '.LocalizedExample::DIRECTORY.'/'.LocalizedExample::PHP_DIRECTORY.'. Review the difference; when it is intended, write the new files there.')
        ->and($php)->toBe(LocalizedExample::goldenPhp())
        ->and(array_keys($typeScript))->toBe([LocalizedExample::TYPESCRIPT_DIRECTORY.'/records/BlogPostV1.ts', LocalizedExample::TYPESCRIPT_DIRECTORY.'/validation.ts'])
        ->and($typeScript)->toBe(LocalizedExample::goldenTypeScript())
        ->and($php['Generated/QueryBuilders/BlogPost/BlogPostQuery.php'])->toContain('public function locale(Locale $locale): self')
        ->and($php['Generated/GeneratedTypeCatalog.php'])->toContain('Localization::Variants')
        ->and($php['Generated/GeneratedTypeCatalog.php'])->toContain('fallback: FieldFallback::Field')
        ->and($php['Generated/Validators/BlogPostValidator.php'])->toContain("                    new FieldHandle('title'),\n                    Presence::Required,\n                    [\n                        new Rule(RuleName::String),\n                        new Rule(RuleName::MaxLength, ['120']),\n                    ],\n                    [],\n                    true,\n");
});

it('says which variant holds each field and how it falls back, the nested fields of a translatable group with the group', function (): void {
    $type = localizedDescriptor();
    $quotes = array_find($type->fields, static fn (FieldDescriptor $field): bool => $field->handle->value === 'quotes');

    expect($type->capabilities->localization)->toBe(Localization::Variants)
        ->and(localizedFields($type))->toBe([
            'author_email' => [false, 'variant'],
            'body' => [true, 'variant'],
            'category' => [false, 'variant'],
            'ext__app__subtitle' => [true, 'variant'],
            'ext__app__tag' => [false, 'variant'],
            'published_on' => [false, 'variant'],
            'quotes' => [true, 'variant'],
            'teaser' => [true, 'field'],
            'title' => [true, 'variant'],
        ])
        ->and(array_map(static fn (FieldDescriptor $field): array => [$field->handle->value, $field->translatable, $field->fallback], $quotes->fields ?? []))->toBe([['source', true, FieldFallback::Variant], ['text', true, FieldFallback::Variant]]);
});
