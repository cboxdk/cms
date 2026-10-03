<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\PanelTypes;

use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Boundary\JsonSchemaShapes;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\Declarations;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\JsonShape;
use Cbox\Cms\Generators\PanelTypes\Domain\ShapeDeclarations;
use Cbox\Cms\Generators\PanelTypes\Domain\ShapeKind;
use Cbox\Cms\Generators\PanelTypes\Domain\TypeScriptLayout;
use Cbox\Cms\Tests\Support\Node;

/*
 * The JSON Schemas cms:panel:types types (PRD 13.4): every command's document and every query's
 * result the kernel's codecs carry reads into shapes and declares as TypeScript, the keywords that
 * only narrow values are left to the codec, and a keyword whose structure has no TypeScript form is
 * refused at its place.
 */

it('types the document of every command and the result of every query the kernel has a codec of, as TypeScript tsc accepts', function (): void {
    $schemas = [];

    foreach (app(CommandCodecs::class)->all() as $codec) {
        $schemas['command '.$codec->command->value.'@'.$codec->version] = $codec->schema->json;
    }

    foreach (app(QueryCodecs::class)->all() as $codec) {
        $schemas['query result '.$codec->name->value.'@'.$codec->version] = $codec->resultSchema->json;
    }

    expect($schemas)->not->toBeEmpty();

    $lines = [];
    $imports = [];

    foreach (array_keys($schemas) as $index => $what) {
        $stem = 'Probe'.$index;
        $declarations = ShapeDeclarations::of(JsonSchemaShapes::read($schemas[$what], $what), $stem, 'V1', $what);
        $lines = [...$lines, ...$declarations->lines];
        $imports = [...$imports, ...$declarations->imports];

        expect($declarations->name)->toBe($stem.'V1');
    }

    $imports = array_values(array_unique($imports));
    $module = implode("\n", [...($imports === [] ? [] : TypeScriptLayout::import($imports, '@cboxdk/cms-panel/extend')), ...$lines, '']);
    $tsc = Node::withProbe('ts', $module, static fn (): mixed => Node::tool('tsc', ['--noEmit']));

    expect($tsc->getExitCode())->toBe(0, $tsc->getOutput().$tsc->getErrorOutput());
});

it('reads kinds, literals, members, items, unions and references', function (): void {
    $document = JsonSchemaShapes::read(PanelTypesFixtures::REVIEW_REQUEST, 'a schema');
    $members = [];

    foreach ($document->root->properties as $property) {
        $members[$property->name] = [$property->shape->kind, $property->required];
    }

    expect($document->root->kind)->toBe(ShapeKind::Object)
        ->and($document->root->closed)->toBeTrue()
        ->and($members)->toBe([
            'note' => [ShapeKind::String, true],
            'priority' => [ShapeKind::Union, true],
            'labels' => [ShapeKind::Object, false],
            'reviewer' => [ShapeKind::Object, false],
        ])
        ->and(array_map(static fn (JsonShape $member): ?string => $member->literal, $document->root->properties[1]->shape->members))->toBe(['1', '2', '3', 'null']);
});

it('refuses a keyword with no TypeScript form, a reference outside $defs and a document that is no object', function (string $json, string $problem): void {
    $failed = null;

    try {
        JsonSchemaShapes::read($json, 'the schema of the command probe@1');
    } catch (GenerationFailed $refused) {
        $failed = $refused;
    }

    expect($failed)->toBeInstanceOf(GenerationFailed::class);
    assert($failed instanceof GenerationFailed);
    expect($failed->problems[0]->code)->toBe(GenerateErrorCode::SchemaInvalid)
        ->and($failed->problems[0]->describe())->toContain($problem);
})->with([
    'patternProperties' => ['{"type":"object","patternProperties":{"^a":{"type":"string"}}}', 'The schema of the command probe@1 at # has the keyword "patternProperties"'],
    'prefixItems below a member' => ['{"type":"object","properties":{"pair":{"type":"array","prefixItems":[{"type":"string"}]}}}', 'at #/properties/pair has the keyword "prefixItems"'],
    'a reference to another document' => ['{"$ref":"other.json#/x"}', 'at #/$ref is not a reference to a definition'],
    'an unknown type' => ['{"type":"date"}', 'names the type "date"'],
    'not JSON' => ['{', 'is not JSON'],
    'a list' => ['[1]', 'is not a JSON object'],
]);

it('refuses a reference to a definition the document does not have', function (): void {
    expect(fn (): Declarations => ShapeDeclarations::of(JsonSchemaShapes::read('{"$ref":"#/$defs/missing"}', 'a schema'), 'Probe', 'V1', 'A probe.'))
        ->toThrow(GenerationFailed::class, 'refers to the definition "missing"');
});
