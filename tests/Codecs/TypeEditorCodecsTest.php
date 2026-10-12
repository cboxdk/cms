<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs;

use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\TypeEditorCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Testkit\Editors\SampleEditors;
use Cbox\Cms\Tests\Support\Phpstan;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Workbench\App\Cms\Generated\GeneratedTypeEditors;

/*
 * The type editor descriptor's codec, contract version 1 (PRD 11.12, 14.1, GUARDRAILS 2.2): every
 * descriptor of the workbench's generated TypeEditors, and the sample descriptors of the testkit,
 * go through TypeEditorCodecV1 into JSON that type-editor.v1.json accepts, by opis/json-schema,
 * and back into an equal descriptor, as each reader sees it; and a document that breaks the
 * contract is refused at its value.
 */

const TYPE_EDITOR_SCHEMA = 'type-editor.v1.json';

function typeEditorSchema(): string
{
    $contents = file_get_contents(Phpstan::root().'/'.ProtocolSchemas::SCHEMA_DIRECTORY.'/'.TYPE_EDITOR_SCHEMA);

    return $contents === false ? throw new RuntimeException('The schema cannot be read.') : $contents;
}

/**
 * The first error of opis's verdict on the document, or null when the schema accepts it.
 */
function typeEditorSchemaError(string $document): ?string
{
    $error = new Validator()->validate(json_decode($document, false, 64, JSON_THROW_ON_ERROR), typeEditorSchema())->error();

    return $error instanceof ValidationError ? $error->keyword().' at '.implode('/', $error->data()->fullPath()) : null;
}

/**
 * @return array<string, array{TypeEditor}>
 */
function typeEditors(): array
{
    $editors = [];

    foreach ([...new GeneratedTypeEditors()->all(), ...SampleEditors::many(2)] as $editor) {
        $editors[$editor->name->value] = [$editor];
    }

    return $editors;
}

it('writes every descriptor as JSON the committed schema accepts, and reads it back into an equal descriptor, for each reader', function (TypeEditor $editor): void {
    $codec = new TypeEditorCodecV1;

    foreach (ClassificationAccess::cases() as $access) {
        foreach ([false, true] as $agent) {
            $visible = $editor->visibleTo($access, $agent);
            $json = $codec->encode($visible, $access);

            expect(typeEditorSchemaError($json))->toBeNull($editor->name->value.' at '.$access->value)
                ->and($codec->decode($json, $access))->toEqual($visible)
                ->and($codec->encode($codec->decode($json, $access), $access))->toBe($json);
        }
    }

    expect($codec->encode($editor, ClassificationAccess::Sensitive))->toContain('"title_field":')
        ->and(json_decode($codec->encode($editor, ClassificationAccess::Sensitive), true, 64, JSON_THROW_ON_ERROR))->toHaveKeys(['capabilities', 'description', 'extensions', 'fields', 'id', 'label', 'name', 'title_field', 'version']);
})->with(typeEditors(...));

it('refuses a document that breaks the contract at its value, as the schema does', function (string $change, string $path, bool $schemaRefuses): void {
    $codec = new TypeEditorCodecV1;
    $document = json_decode($codec->encode(SampleEditors::type(1), ClassificationAccess::Sensitive), true, 64, JSON_THROW_ON_ERROR);
    expect($document)->toBeArray();
    $edited = json_decode($change, true, 64, JSON_THROW_ON_ERROR);
    $json = json_encode(is_array($document) && is_array($edited) ? array_replace($document, $edited) : [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    try {
        $codec->decode($json, ClassificationAccess::Sensitive);
    } catch (DecodingFailed $failed) {
        expect($failed->path?->toString() ?? '')->toBe($path)
            ->and(typeEditorSchemaError($json) !== null)->toBe($schemaRefuses);

        return;
    }

    throw new RuntimeException('The document was accepted.');
})->with([
    'a version below 1' => ['{"version":0}', 'version', true],
    'a name that is not <owner>:<handle>' => ['{"name":"card"}', 'name', true],
    'a label that is too long' => ['{"label":"'.str_repeat('a', 101).'"}', 'label', true],
    'a title field the type does not have' => ['{"title_field":"absent"}', '', false],
    'a capability the kernel does not know' => ['{"capabilities":{"history":"all","localization":"none","routable":true,"stages":"none"}}', 'capabilities.history', true],
    'fields that are not a list' => ['{"fields":"none"}', 'fields', true],
]);
